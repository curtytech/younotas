<?php

namespace Database\Seeders;

use App\Models\FocusNfeSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class FocusTestCompanySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'MIL DE MAGE CENTRO AUTOMOTIVO LTDA', 'password' => 'password', 'role' => 'enterprise', 'email_verified_at' => now()],
        );

        $user->forceFill([
            'name' => 'MIL DE MAGE CENTRO AUTOMOTIVO LTDA',
            'cnpj' => '28.480.405/0001-93',
            'razao_social' => 'MIL DE MAGE CENTRO AUTOMOTIVO LTDA',
            'inscricao_estatual' => '87418464',
            'address' => 'Avenida Nossa Senhora da Piedade',
            'address_number' => '165',
            'address_complement' => null,
            'city' => 'Magé',
            'state' => 'RJ',
            'zip_code' => '25901-094',
        ])->save();

        $apiKey = (string) config('services.focus_nfe.api_key');

        if (blank($apiKey)) {
            logger()->warning('FOCUS_NFE_API_KEY não configurada; usando chave fictícia no seeder.');
            $apiKey = 'FOCUS_API_KEY_NAO_CONFIGURADA';
        }

        FocusNfeSetting::updateOrCreate(
            ['user_id' => $user->id],
            ['settings' => [
                'api_key' => $apiKey,
                'api_password' => (string) config('services.focus_nfe.api_password', ''),
                'base_url' => (string) config('services.focus_nfe.base_url', 'https://homologacao.focusnfe.com.br'),
                'prestador' => [
                    'cnpj' => '28480405000193',
                    'inscricao_municipal' => '1005235',
                    'codigo_municipio' => '3302502',
                ],
                'nfse' => [
                    'natureza_operacao' => '1',
                    'optante_simples_nacional' => true,
                    'incentivador_cultural' => false,
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
                        'nome' => 'MIL DE MAGE CENTRO AUTOMOTIVO LTDA',
                        'nome_fantasia' => 'REI TESTE GNV',
                        'logradouro' => 'Avenida Nossa Senhora da Piedade',
                        'numero' => '165',
                        'bairro' => 'Jardim Nossa Senhora da Piedade',
                        'municipio' => 'Magé',
                        'uf' => 'RJ',
                        'cep' => '25901094',
                        'inscricao_estadual' => '87418464',
                        'regime_tributario' => 1,
                    ],
                ],
            ]],
        );
    }
}
