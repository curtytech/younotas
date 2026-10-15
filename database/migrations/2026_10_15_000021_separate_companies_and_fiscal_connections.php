<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $ownedTables = [
        'focus_nfe_settings', 'clients', 'products', 'services', 'technicians', 'sales',
        'service_orders', 'stock_movements', 'vehicle', 'drivers', 'fines', 'appeals', 'fiscal_documents',
    ];

    public function up(): void
    {
        if (DB::table('focus_nfe_settings')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Existem usuários com múltiplas configurações fiscais legadas. Resolva a propriedade desses cadastros antes de migrar.');
        }

        // Validate decryption and legacy credential routing before any schema mutation.
        DB::table('focus_nfe_settings')->orderBy('id')->each(function (object $setting): void {
            $settings = $this->decode($setting->settings, true);

            if (filled($settings['api_key'] ?? null)
                && ! in_array($settings['environment'] ?? null, ['homologacao', 'producao'], true)
                && $this->environmentFromEvidence(['base_url' => $settings['base_url'] ?? null]) === null) {
                throw new RuntimeException('Uma credencial fiscal legada não possui ambiente comprovado. Identifique seu ambiente antes de migrar.');
            }
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('cnpj', 18)->nullable()->index();
            $table->string('default_environment', 20)->default('homologacao');
            $table->boolean('is_active')->default(true);
            $table->longText('fiscal_settings')->nullable();
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'manager', 'operator', 'viewer'])->default('viewer');
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('primary_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
        });

        Schema::create('fiscal_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('focus');
            $table->string('environment', 20);
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(false);
            $table->longText('settings')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider', 'environment'], 'fiscal_connection_identity_unique');
        });

        foreach ($this->ownedTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
                $table->dropForeign(['user_id']);
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasIndex('focus_nfe_settings', ['user_id'], 'unique')) {
            Schema::table('focus_nfe_settings', fn (Blueprint $table) => $table->dropUnique(['user_id']));
        }

        Schema::table('focus_nfe_settings', function (Blueprint $table): void {
            $table->unique('company_id');
        });

        foreach (['sales' => 'nfe', 'service_orders' => 'nfse'] as $tableName => $type) {
            Schema::table($tableName, function (Blueprint $table) use ($type): void {
                $table->foreignId("focus_{$type}_connection_id")->nullable()->constrained('fiscal_connections')->restrictOnDelete();
                $table->string("focus_{$type}_environment", 20)->nullable()->index();
                $table->longText("focus_{$type}_configuration_snapshot")->nullable();
                $table->timestamp("focus_{$type}_next_check_at")->nullable()->index();
            });
        }

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->foreignId('fiscal_connection_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('environment', 20)->nullable()->index();
            $table->longText('configuration_snapshot')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dropUnique(['user_id', 'focus_reference']);
            $table->dropUnique(['user_id', 'access_key']);
            $table->unique(['company_id', 'fiscal_connection_id', 'focus_reference'], 'fiscal_documents_connection_reference_unique');
            $table->unique(['company_id', 'environment', 'access_key'], 'fiscal_documents_company_environment_key_unique');
            $table->index(['company_id', 'environment', 'status', 'issued_at'], 'fiscal_documents_company_status_index');
        });

        Schema::create('fiscal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->longText('context')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $setting = DB::table('focus_nfe_settings')->where('user_id', $user->id)->first();
            $ownsRecords = $setting !== null || collect($this->ownedTables)->contains(
                fn (string $table): bool => DB::table($table)->where('user_id', $user->id)->exists()
            );

            if ($user->role !== 'enterprise' && ! $ownsRecords) {
                return;
            }

            $settings = $this->decode($setting?->settings, true);
            $environment = $settings['environment'] ?? $this->environmentFromEvidence(['base_url' => $settings['base_url'] ?? null]) ?? 'homologacao';
            $environment = in_array($environment, ['homologacao', 'producao'], true) ? $environment : 'homologacao';
            $tokens = $settings['api_keys'] ?? [];

            if (filled($settings['api_key'] ?? null)) {
                $legacyEnvironment = in_array($settings['environment'] ?? null, ['homologacao', 'producao'], true)
                    ? $settings['environment']
                    : $this->environmentFromEvidence(['base_url' => $settings['base_url'] ?? null]);

                if ($legacyEnvironment !== null) {
                    $tokens[$legacyEnvironment] = $settings['api_key'];
                }
            }

            $common = $this->sanitize($settings);
            unset($common['environment'], $common['base_url'], $common['company_id'], $common['fiscal_connection_id']);
            $common = array_replace_recursive($this->legacyFiscalSettingsFor($user), $common);
            $companyId = DB::table('companies')->insertGetId([
                'name' => data_get($common, 'nfe.emitente.nome') ?: $user->name,
                'cnpj' => data_get($common, 'prestador.cnpj') ?: ($user->cnpj ?? null),
                'default_environment' => $environment,
                'is_active' => true,
                'fiscal_settings' => $this->encryptArray($common),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('company_user')->insert([
                'company_id' => $companyId, 'user_id' => $user->id, 'role' => 'owner',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $user->id)->update(['primary_company_id' => $companyId]);

            $connections = [];

            foreach (['homologacao', 'producao'] as $connectionEnvironment) {
                $token = $tokens[$connectionEnvironment] ?? null;
                $connections[$connectionEnvironment] = DB::table('fiscal_connections')->insertGetId([
                    'company_id' => $companyId, 'provider' => 'focus', 'environment' => $connectionEnvironment,
                    'api_key' => filled($token) ? Crypt::encryptString((string) $token) : null,
                    'enabled' => filled($token), 'settings' => $this->encryptArray([]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ($this->ownedTables as $table) {
                DB::table($table)->where('user_id', $user->id)->update(['company_id' => $companyId]);
            }

            if ($setting !== null) {
                DB::table('focus_nfe_settings')->where('id', $setting->id)->update([
                    'settings' => $this->encryptArray($common + ['environment' => $environment]),
                ]);
            }

            foreach (['sales' => 'nfe', 'service_orders' => 'nfse'] as $table => $type) {
                DB::table($table)->where('company_id', $companyId)->whereNotNull("focus_{$type}_ref")->orderBy('id')
                    ->each(function (object $source) use ($table, $type, $companyId, $connections, $common): void {
                        $evidence = [
                            'url' => $source->{"focus_{$type}_url"} ?? null,
                            'response' => $this->decode($source->{"focus_{$type}_response_secure"} ?? null)
                                ?: $this->decode($source->{"focus_{$type}_response"} ?? null),
                            'payload' => $this->decode($source->{"focus_{$type}_payload"} ?? null),
                        ];
                        $documentEnvironment = $this->environmentFromEvidence($evidence);

                        if ($documentEnvironment === null) {
                            return;
                        }

                        DB::table($table)->where('id', $source->id)->update([
                            "focus_{$type}_environment" => $documentEnvironment,
                            "focus_{$type}_connection_id" => $connections[$documentEnvironment],
                            "focus_{$type}_configuration_snapshot" => $this->encryptArray($common + [
                                'company_id' => $companyId, 'environment' => $documentEnvironment,
                                'fiscal_connection_id' => $connections[$documentEnvironment],
                                'base_url' => $this->urlFor($documentEnvironment),
                            ]),
                        ]);
                    });
            }

            DB::table('fiscal_documents')->where('company_id', $companyId)->orderBy('id')
                ->each(function (object $document) use ($companyId, $connections, $common): void {
                    $documentEnvironment = $this->environmentFromEvidence([
                        'url' => $document->document_url,
                        'response' => $this->decode($document->raw_response),
                        'metadata' => $this->decode($document->metadata),
                    ]);

                    if ($documentEnvironment === null) {
                        return;
                    }

                    DB::table('fiscal_documents')->where('id', $document->id)->update([
                        'environment' => $documentEnvironment,
                        'fiscal_connection_id' => $connections[$documentEnvironment],
                        'configuration_snapshot' => $this->encryptArray($common + [
                            'company_id' => $companyId, 'environment' => $documentEnvironment,
                            'fiscal_connection_id' => $connections[$documentEnvironment],
                            'base_url' => $this->urlFor($documentEnvironment),
                        ]),
                    ]);
                });
        });
    }

    private function decode(?string $value, bool $strict = false): array
    {
        if (blank($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new RuntimeException('A configuração fiscal deve conter um objeto válido.');
            }

            return $decoded;
        } catch (Throwable) {
            if ($strict) {
                throw new RuntimeException('Não foi possível decifrar uma configuração fiscal legada. Verifique a chave da aplicação antes de migrar.');
            }

            return [];
        }
    }

    private function sanitize(array $settings): array
    {
        $result = [];

        foreach ($settings as $key => $value) {
            if (preg_match('/api[_-]?keys?|password|token|secret|authorization/i', (string) $key)) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $result;
    }

    private function environmentFromEvidence(array $evidence): ?string
    {
        $found = [];
        $walk = function (array $values) use (&$walk, &$found): void {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                } elseif (is_scalar($value)) {
                    if (in_array((string) $key, ['ambiente', 'environment', 'tpAmb'], true)) {
                        $environment = match ((string) $value) {
                            '1', 'producao', 'production' => 'producao',
                            '2', 'homologacao', 'homologation' => 'homologacao',
                            default => null,
                        };

                        if ($environment !== null) {
                            $found[$environment] = true;
                        }
                    }

                    $host = parse_url((string) $value, PHP_URL_HOST);

                    if ($host === 'api.focusnfe.com.br') {
                        $found['producao'] = true;
                    } elseif ($host === 'homologacao.focusnfe.com.br') {
                        $found['homologacao'] = true;
                    }

                    if (preg_match('/<tpAmb>\s*([12])\s*<\/tpAmb>/', (string) $value, $matches)) {
                        $found[$matches[1] === '1' ? 'producao' : 'homologacao'] = true;
                    }
                }
            }
        };
        $walk($evidence);

        return count($found) === 1 ? array_key_first($found) : null;
    }

    private function urlFor(string $environment): string
    {
        return $environment === 'producao' ? 'https://api.focusnfe.com.br' : 'https://homologacao.focusnfe.com.br';
    }

    private function encryptArray(array $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function legacyFiscalSettingsFor(object $user): array
    {
        $emitente = array_filter([
            'nome' => ($user->razao_social ?? null) ?: $user->name,
            'logradouro' => $user->address ?? null,
            'numero' => $user->address_number ?? null,
            'municipio' => $user->city ?? null,
            'uf' => $user->state ?? null,
            'cep' => $user->zip_code ?? null,
            'inscricao_estadual' => $user->inscricao_estatual ?? null,
        ], fn (mixed $value): bool => filled($value));
        $result = ['nfe' => ['emitente' => $emitente]];

        if (filled($user->cnpj ?? null)) {
            $result['prestador']['cnpj'] = $user->cnpj;
        }

        return $result;
    }

    public function down(): void
    {
        // Keep the companies, credential identities and fiscal history. A rollback must
        // not silently merge environments or restore destructive user cascades.
        throw new RuntimeException('Migração fiscal de preservação: restaure um backup validado para reverter a estrutura de empresas.');
    }
};
