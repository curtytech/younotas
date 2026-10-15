<?php

namespace App\Services;

use App\Models\Company;

/** Lists registration, credential and issuer gaps per company and environment before an emission is attempted. */
class FiscalReadiness
{
    private const NFSE_FIELDS = [
        'prestador.cnpj' => 'CNPJ do prestador',
        'prestador.inscricao_municipal' => 'Inscrição municipal',
        'prestador.codigo_municipio' => 'Código IBGE do município do prestador',
        'nfse.natureza_operacao' => 'Natureza da operação da NFS-e',
    ];

    private const NFE_FIELDS = [
        'nfe.emitente.nome' => 'Razão social do emitente',
        'nfe.emitente.logradouro' => 'Logradouro do emitente',
        'nfe.emitente.numero' => 'Número do endereço do emitente',
        'nfe.emitente.bairro' => 'Bairro do emitente',
        'nfe.emitente.municipio' => 'Município do emitente',
        'nfe.emitente.uf' => 'UF do emitente',
        'nfe.emitente.cep' => 'CEP do emitente',
        'nfe.emitente.inscricao_estadual' => 'Inscrição estadual',
        'nfe.emitente.regime_tributario' => 'Regime tributário',
        'nfe.natureza_operacao' => 'Natureza da operação da NF-e',
        'nfe.cfop_padrao' => 'CFOP padrão',
        'nfe.icms_origem' => 'Origem da mercadoria (ICMS)',
        'nfe.icms_situacao_tributaria' => 'Situação tributária do ICMS',
        'nfe.pis_situacao_tributaria' => 'Situação tributária do PIS',
        'nfe.cofins_situacao_tributaria' => 'Situação tributária do COFINS',
    ];

    /** @return array{environment: string, ready: bool, items: list<array{key: string, label: string, ok: bool, hint: string}>} */
    public function check(Company $company, string $environment): array
    {
        $connection = $company->connections()->where('provider', 'focus')->where('environment', $environment)->first();
        $items = [
            $this->item('connection', 'Conexão Focus cadastrada', (bool) $connection, 'Salve a configuração fiscal para criar a conexão deste ambiente.'),
            $this->item('credential', 'Credencial configurada', filled($connection?->api_key), 'Informe o token da Focus para este ambiente.'),
            $this->item('enabled', 'Conexão habilitada', (bool) $connection?->enabled, 'Habilite a conexão depois de cadastrar o token.'),
        ];

        $settings = array_replace_recursive($company->fiscal_settings ?? [], $connection?->settings ?? []);
        $documents = array_replace(['nfe' => true, 'nfse' => true], $settings['documents'] ?? []);

        if (! $documents['nfe'] && ! $documents['nfse']) {
            $items[] = $this->item('documents', 'Ao menos um tipo de documento habilitado', false, 'Habilite NF-e ou NFS-e neste ambiente.');
        }

        foreach ([['nfse', 'NFS-e', self::NFSE_FIELDS], ['nfe', 'NF-e', self::NFE_FIELDS]] as [$type, $label, $fields]) {
            if (! $documents[$type]) {
                continue;
            }

            foreach ($fields as $path => $field) {
                $items[] = $this->item($type.'.'.$path, $label.': '.$field, filled(data_get($settings, $path)), 'Preencha este campo na Configuração Fiscal.');
            }
        }

        return [
            'environment' => $environment,
            'ready' => collect($items)->every(fn (array $item): bool => $item['ok']),
            'items' => $items,
        ];
    }

    /** @return array<string, array{environment: string, ready: bool, items: list<array<string, mixed>>}> */
    public function checkAll(Company $company): array
    {
        return collect(array_keys(FocusNfeConfigService::ENVIRONMENT_URLS))
            ->mapWithKeys(fn (string $environment): array => [$environment => $this->check($company, $environment)])->all();
    }

    private function item(string $key, string $label, bool $ok, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'hint' => $hint];
    }
}
