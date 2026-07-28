<?php

namespace Database\Factories;

use App\Models\FocusNfeSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FocusNfeSetting> */
class FocusNfeSettingFactory extends Factory
{
    protected $model = FocusNfeSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'settings' => [
                'api_key' => (string) config('services.focus_nfe.api_key', ''),
                'api_password' => (string) config('services.focus_nfe.api_password', ''),
                'base_url' => 'https://homologacao.focusnfe.com.br',
                'prestador' => [
                    'cnpj' => '04252011000110',
                    'inscricao_municipal' => '12345678',
                    'codigo_municipio' => '3550308',
                ],
                'nfe' => [
                    'natureza_operacao' => 'VENDA DE MERCADORIA',
                    'tipo_documento' => 1,
                    'finalidade_emissao' => 1,
                    'consumidor_final' => 1,
                    'presenca_comprador' => 1,
                    'modalidade_frete' => 9,
                    'forma_pagamento' => '01',
                    'cfop_padrao' => '5102',
                    'icms_origem' => '0',
                    'icms_situacao_tributaria' => '102',
                    'pis_situacao_tributaria' => '07',
                    'cofins_situacao_tributaria' => '07',
                    'emitente' => [
                        'nome' => 'Empresa Teste NF-e',
                        'nome_fantasia' => 'Empresa Teste',
                        'logradouro' => 'Rua Augusta',
                        'numero' => '500',
                        'bairro' => 'Consolação',
                        'municipio' => 'São Paulo',
                        'uf' => 'SP',
                        'cep' => '01305000',
                        'inscricao_estadual' => '110042490114',
                        'regime_tributario' => 1,
                    ],
                ],
            ],
        ];
    }
}
