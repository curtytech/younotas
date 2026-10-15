<?php

use App\Models\Company;
use App\Models\FiscalConnection;
use App\Models\FiscalDocument;
use App\Models\FiscalEvent;
use App\Models\FocusNfeSetting;
use App\Models\Sale;
use App\Models\User;
use App\Services\FocusNfeConfigService;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('requires an explicit company connection instead of inheriting global fiscal credentials', function () {
    config(['services.focus_nfe.api_key' => 'global-test-token', 'services.focus_nfe.api_keys.producao' => 'global-production-test-token']);
    $user = User::factory()->create(['role' => 'enterprise']);

    expect(fn () => app(FocusNfeConfigService::class)->forUser($user))->toThrow(RuntimeException::class, 'sem token próprio');
    expect($user->fresh()->primaryCompany->connections)->toHaveCount(2);
    expect($user->fresh()->primaryCompany->connections->every(fn (FiscalConnection $connection): bool => blank($connection->api_key)))->toBeTrue();
});

it('moves legacy credentials to two encrypted connections and keeps the shared issuer settings once', function () {
    $user = User::factory()->create(['role' => 'enterprise']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $user->id, 'settings' => [
        'environment' => 'homologacao',
        'api_keys' => ['homologacao' => 'test-hml-token', 'producao' => 'test-prod-token'],
        'prestador' => ['cnpj' => '04252011000110'],
        'nfe' => ['cfop_padrao' => '5102'],
    ]]);
    $company = $setting->company;
    $connections = $company->connections()->orderBy('id')->get()->keyBy('environment');

    expect($company->members->first()->pivot->role)->toBe('owner')
        ->and($company->fiscal_settings['nfe']['cfop_padrao'])->toBe('5102')
        ->and($setting->fresh()->settings)->not->toHaveKeys(['api_key', 'api_keys', 'api_password'])
        ->and($connections['homologacao']->api_key)->toBe('test-hml-token')
        ->and($connections['producao']->api_key)->toBe('test-prod-token')
        ->and($connections['homologacao']->toArray())->not->toHaveKey('api_key');
    $stored = DB::table('fiscal_connections')->where('id', $connections['homologacao']->id)->value('api_key');
    expect($stored)->not->toContain('test-hml-token')
        ->and(Crypt::decryptString($stored))->toBe('test-hml-token');
});

it('keeps a document on its original environment and settings while using the rotated secret', function () {
    $service = app(FocusNfeConfigService::class);
    $user = User::factory()->create(['role' => 'enterprise']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $user->id, 'settings' => [
        'environment' => 'homologacao',
        'api_keys' => ['homologacao' => 'test-old-token', 'producao' => 'test-prod-token'],
        'nfe' => ['cfop_padrao' => '5102'],
    ]]);
    $config = $service->forUser($user);
    $document = FiscalDocument::factory()->create([
        'user_id' => $user->id, 'company_id' => $config['company_id'],
        'fiscal_connection_id' => $config['fiscal_connection_id'], 'environment' => $config['environment'],
        'configuration_snapshot' => FocusNfeConfigService::sanitizeSnapshot($config),
    ]);
    $user->load('focusNfeSetting');
    $setting->update(['settings' => ['environment' => 'producao', 'api_keys' => ['homologacao' => 'test-rotated-token'], 'nfe' => ['cfop_padrao' => '6102']]]);

    $preserved = $service->forDocument($document);
    expect($service->forUser($user)['environment'])->toBe('producao')
        ->and($preserved['environment'])->toBe('homologacao')
        ->and($preserved['api_key'])->toBe('test-rotated-token')
        ->and($preserved['fiscal_connection_id'])->toBe($config['fiscal_connection_id'])
        ->and($preserved['nfe']['cfop_padrao'])->toBe('5102')
        ->and($document->fresh()->configuration_snapshot)->not->toHaveKeys(['api_key', 'api_keys', 'api_password']);
});

it('refuses ambiguous legacy documents and mismatched company connections', function () {
    $service = app(FocusNfeConfigService::class);
    $legacy = FiscalDocument::factory()->create();
    expect(fn () => $service->forDocument($legacy))->toThrow(RuntimeException::class, 'sem ambiente/conexão');

    $connection = FiscalConnection::factory()->create();
    $otherCompany = Company::factory()->create();
    $document = FiscalDocument::factory()->create([
        'company_id' => $otherCompany->id, 'fiscal_connection_id' => $connection->id,
        'environment' => 'homologacao', 'configuration_snapshot' => ['nfe' => ['cfop_padrao' => '5102']],
    ]);
    expect(fn () => $service->forDocument($document))->toThrow(RuntimeException::class, 'não corresponde');
});

it('preserves omitted tokens, supports explicit removal, and never silently enables a disabled connection', function () {
    $setting = FocusNfeSetting::factory()->create(['settings' => [
        'environment' => 'homologacao', 'api_keys' => ['homologacao' => 'test-initial-token'],
    ]]);
    $connection = $setting->company->connections()->where('environment', 'homologacao')->firstOrFail();
    $setting->update(['settings' => ['environment' => 'homologacao', 'api_keys' => ['homologacao' => '']]]);
    expect($connection->fresh()->api_key)->toBe('test-initial-token');

    $connection->update(['enabled' => false]);
    $setting->update(['settings' => ['environment' => 'homologacao', 'api_keys' => ['homologacao' => 'test-rotated-token']]]);
    expect($connection->fresh()->enabled)->toBeFalse();
    expect(fn () => app(FocusNfeConfigService::class)->forConnection($connection))->toThrow(RuntimeException::class, 'desativada');

    $setting->update(['settings' => ['environment' => 'homologacao', 'remove_api_keys' => ['homologacao' => true]]]);
    expect($connection->fresh()->api_key)->toBeNull()->and($connection->fresh()->enabled)->toBeFalse();
});

it('uses company memberships and distinct fiscal permissions instead of requiring one login per issuer', function () {
    $owner = User::factory()->create(['role' => 'enterprise']);
    $operator = User::factory()->create(['role' => 'enterprise']);
    $viewer = User::factory()->create(['role' => 'enterprise']);
    $company = Company::factory()->create();
    $company->members()->attach([$owner->id => ['role' => 'owner'], $operator->id => ['role' => 'operator'], $viewer->id => ['role' => 'viewer']]);
    $otherCompany = Company::factory()->create();
    $otherCompany->members()->attach($owner->id, ['role' => 'manager']);
    $sharedDocument = FiscalDocument::factory()->create(['user_id' => $owner->id, 'company_id' => $company->id]);
    $privateDocument = FiscalDocument::factory()->create(['user_id' => $owner->id, 'company_id' => $otherCompany->id]);

    expect($operator->canAccessCompany($company, 'issue'))->toBeTrue()
        ->and($operator->canAccessCompany($company, 'manage_fiscal'))->toBeFalse()
        ->and($viewer->canAccessCompany($company, 'view'))->toBeTrue()
        ->and($viewer->canAccessCompany($company, 'issue'))->toBeFalse()
        ->and($operator->canAccessCompany($otherCompany))->toBeFalse()
        ->and($owner->canAccessCompany($otherCompany, 'manage_fiscal'))->toBeTrue()
        ->and($owner->canAccessCompany($otherCompany, 'unknown_permission'))->toBeFalse()
        ->and(FiscalDocument::query()->visibleTo($operator)->pluck('id')->all())->toContain($sharedDocument->id)->not->toContain($privateDocument->id)
        ->and(FiscalDocument::query()->visibleTo($viewer, 'issue')->exists())->toBeFalse();
    $owner->update(['is_active' => false]);
    expect($owner->canAccessPanel(Panel::make()))->toBeFalse()
        ->and($owner->canAccessCompany($company, 'issue'))->toBeFalse();
});

it('keeps multiple company configurations under the same human without mixing defaults or secrets', function () {
    $user = User::factory()->create(['role' => 'enterprise']);
    $first = FocusNfeSetting::factory()->create(['user_id' => $user->id, 'settings' => [
        'environment' => 'homologacao', 'api_keys' => ['homologacao' => 'test-company-a'],
    ]]);
    $secondCompany = Company::factory()->create(['default_environment' => 'producao']);
    $secondCompany->members()->attach($user->id, ['role' => 'owner']);
    FocusNfeSetting::factory()->create(['user_id' => $user->id, 'company_id' => $secondCompany->id, 'settings' => [
        'environment' => 'producao', 'api_keys' => ['producao' => 'test-company-b'],
    ]]);
    $user->load('primaryCompany');
    User::query()->whereKey($user->id)->update(['primary_company_id' => $secondCompany->id]);

    $service = app(FocusNfeConfigService::class);
    expect($service->forUser($user)['api_key'])->toBe('test-company-b')
        ->and($service->forCompany($first->company)['api_key'])->toBe('test-company-a');
});

it('stores document capabilities per environment and sanitizes nested snapshot credentials', function () {
    $setting = FocusNfeSetting::factory()->create(['settings' => [
        'environment' => 'homologacao', 'api_keys' => ['homologacao' => 'test-hml', 'producao' => 'test-prod'],
        'environments' => ['homologacao' => ['nfe_enabled' => true, 'nfse_enabled' => false], 'producao' => ['nfe_enabled' => false, 'nfse_enabled' => true]],
    ]]);
    $service = app(FocusNfeConfigService::class);
    expect($service->forCompany($setting->company, 'homologacao')['documents'])->toBe(['nfe' => true, 'nfse' => false])
        ->and($service->forCompany($setting->company, 'producao')['documents'])->toBe(['nfe' => false, 'nfse' => true])
        ->and($setting->company->fresh()->fiscal_settings)->not->toHaveKey('environments')
        ->and(FocusNfeConfigService::sanitizeSnapshot(['nfe' => ['cfop_padrao' => '5102', 'api_key' => 'test-secret', 'nested' => ['webhook_secret' => 'test-secret', 'enabled' => true]]]))
        ->toBe(['nfe' => ['cfop_padrao' => '5102', 'nested' => ['enabled' => true]]]);
});

it('prevents identity reassignment and account deletion while retaining fiscal records if an account is removed directly', function () {
    $user = User::factory()->create(['role' => 'enterprise']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $user->id, 'settings' => [
        'environment' => 'homologacao', 'api_keys' => ['homologacao' => 'test-hml'],
    ]]);
    $connection = $setting->company->connections()->where('environment', 'homologacao')->firstOrFail();
    expect(fn () => $connection->update(['environment' => 'producao']))->toThrow(RuntimeException::class, 'imutáveis');
    expect(fn () => $user->delete())->toThrow(RuntimeException::class, 'Desative');

    $document = FiscalDocument::factory()->create([
        'user_id' => $user->id, 'company_id' => $setting->company_id,
        'fiscal_connection_id' => $connection->id, 'environment' => 'homologacao',
        'configuration_snapshot' => ['nfe' => ['cfop_padrao' => '5102']], 'issued_by' => $user->id,
    ]);
    DB::table('users')->where('id', $user->id)->delete();
    expect($document->fresh()->user_id)->toBeNull()->and($document->fresh()->issued_by)->toBeNull()
        ->and($document->fresh()->company_id)->toBe($setting->company_id)
        ->and($setting->fresh())->not->toBeNull();
});

it('backfills proven legacy environments and leaves ambiguous documents unresolved despite the current production default', function () {
    $previousConnection = DB::getDefaultConnection();
    $previousSchema = Schema::getFacadeRoot();
    config(['database.connections.legacy_company_backfill' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('legacy_company_backfill');
    Schema::swap(DB::connection()->getSchemaBuilder());

    try {
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (basename($path) >= '2026_10_15_000021') {
                continue;
            }
            (require $path)->up();
        }

        $userId = DB::table('users')->insertGetId([
            'name' => 'Legacy test issuer', 'email' => 'legacy-backfill@example.test',
            'password' => bcrypt('test-only-password'), 'role' => 'enterprise',
        ]);
        DB::table('focus_nfe_settings')->insert([
            'user_id' => $userId,
            'settings' => Crypt::encryptString(json_encode([
                'environment' => 'producao', 'api_keys' => ['homologacao' => 'test-legacy-hml', 'producao' => 'test-legacy-prod'],
                'prestador' => ['cnpj' => '04252011000110'],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $provenSourceId = DB::table('sales')->insertGetId([
            'user_id' => $userId, 'number' => 'LEGACY-PROVEN', 'sale_date' => '2026-10-01',
            'focus_nfe_ref' => 'legacyproven', 'focus_nfe_url' => 'https://homologacao.focusnfe.com.br/test-danfe.pdf',
        ]);
        $ambiguousSourceId = DB::table('sales')->insertGetId([
            'user_id' => $userId, 'number' => 'LEGACY-UNKNOWN', 'sale_date' => '2026-10-01', 'focus_nfe_ref' => 'legacyunknown',
        ]);
        $provenDocumentId = DB::table('fiscal_documents')->insertGetId([
            'user_id' => $userId, 'document_type' => 'NF-e', 'provider' => 'focus', 'focus_reference' => 'legacyproven',
            'source_type' => Sale::class, 'source_id' => $provenSourceId,
            'raw_response' => Crypt::encryptString(json_encode(['status' => 'autorizado', 'tpAmb' => '2'], JSON_THROW_ON_ERROR)),
        ]);
        $ambiguousDocumentId = DB::table('fiscal_documents')->insertGetId([
            'user_id' => $userId, 'document_type' => 'NF-e', 'provider' => 'focus', 'focus_reference' => 'legacyunknown',
            'source_type' => Sale::class, 'source_id' => $ambiguousSourceId,
        ]);
        (require database_path('migrations/2026_10_15_000021_separate_companies_and_fiscal_connections.php'))->up();

        $companyId = DB::table('users')->where('id', $userId)->value('primary_company_id');
        $connections = DB::table('fiscal_connections')->where('company_id', $companyId)->get()->keyBy('environment');
        expect(DB::table('companies')->where('id', $companyId)->value('default_environment'))->toBe('producao')
            ->and(DB::table('company_user')->where('company_id', $companyId)->where('user_id', $userId)->value('role'))->toBe('owner')
            ->and($connections)->toHaveCount(2)
            ->and(Crypt::decryptString($connections['homologacao']->api_key))->toBe('test-legacy-hml')
            ->and(DB::table('sales')->where('id', $provenSourceId)->value('focus_nfe_environment'))->toBe('homologacao')
            ->and(DB::table('sales')->where('id', $ambiguousSourceId)->value('focus_nfe_environment'))->toBeNull()
            ->and(DB::table('fiscal_documents')->where('id', $provenDocumentId)->value('environment'))->toBe('homologacao')
            ->and(DB::table('fiscal_documents')->where('id', $ambiguousDocumentId)->value('environment'))->toBeNull();
        $snapshot = json_decode(Crypt::decryptString(DB::table('fiscal_documents')->where('id', $provenDocumentId)->value('configuration_snapshot')), true);
        expect($snapshot['fiscal_connection_id'])->toBe($connections['homologacao']->id)
            ->and($snapshot)->not->toHaveKeys(['api_key', 'api_keys']);
    } finally {
        DB::disconnect('legacy_company_backfill');
        DB::setDefaultConnection($previousConnection);
        Schema::swap($previousSchema);
        DB::purge('legacy_company_backfill');
    }
});

it('keeps the legacy form projection synchronized after canonical company changes', function () {
    $setting = FocusNfeSetting::factory()->create(['settings' => [
        'environment' => 'homologacao', 'api_keys' => ['homologacao' => 'test-projection-token'],
        'prestador' => ['cnpj' => '04252011000110'],
        'nfe' => ['emitente' => ['nome' => 'Original test issuer']],
    ]]);
    $setting->company->update(['name' => 'Updated test issuer', 'cnpj' => '28480405000193']);
    $projection = $setting->fresh()->settings;
    $config = app(FocusNfeConfigService::class)->forCompany($setting->company);

    expect($projection['nfe']['emitente']['nome'])->toBe('Updated test issuer')
        ->and($projection['prestador']['cnpj'])->toBe('28480405000193')
        ->and($config['nfe']['emitente']['nome'])->toBe('Updated test issuer')
        ->and($projection)->not->toHaveKeys(['api_key', 'api_keys', 'api_password']);
});

it('keeps the issuer CNPJ stable after fiscal documents exist while accepting formatting-only corrections', function () {
    $company = Company::factory()->create(['cnpj' => '04252011000110']);
    FiscalDocument::factory()->create(['company_id' => $company->id]);
    $company->update(['cnpj' => '04.252.011/0001-10']);

    expect(fn () => $company->update(['cnpj' => '28480405000193']))
        ->toThrow(ValidationException::class, 'documentos fiscais');
    expect($company->fresh()->cnpj)->toBe('04.252.011/0001-10');
});

it('records who changed the default environment for new emissions', function () {
    $user = User::factory()->create(['role' => 'enterprise']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $user->id, 'settings' => [
        'environment' => 'homologacao', 'api_keys' => ['homologacao' => 'test-hml', 'producao' => 'test-prod'],
    ]]);
    $company = $setting->company->fresh();
    $this->actingAs($user);

    $company->update(['name' => 'Renomeada']);
    expect(FiscalEvent::query()->where('company_id', $company->id)->where('event', 'default_environment_changed')->count())->toBe(0);

    $company->update(['default_environment' => 'producao']);
    $event = FiscalEvent::query()->where('company_id', $company->id)->where('event', 'default_environment_changed')->sole();

    expect($event->actor_id)->toBe($user->id)
        ->and($event->context)->toBe(['from' => 'homologacao', 'to' => 'producao']);
});
