<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'focus_nfe' => [
        'api_key' => env('FOCUS_NFE_API_KEY'),
        'api_password' => env('FOCUS_NFE_API_PASSWORD', ''),
        'base_url' => env('FOCUS_NFE_BASE_URL', 'https://homologacao.focusnfe.com.br'),
        'prestador' => [
            'cnpj' => env('FOCUS_NFE_PRESTADOR_CNPJ'),
            'inscricao_municipal' => env('FOCUS_NFE_PRESTADOR_INSCRICAO_MUNICIPAL'),
            'codigo_municipio' => env('FOCUS_NFE_PRESTADOR_CODIGO_MUNICIPIO'),
        ],
        'nfse' => [
            'natureza_operacao' => env('FOCUS_NFE_NFSE_NATUREZA_OPERACAO', '1'),
            'incentivador_cultural' => env('FOCUS_NFE_NFSE_INCENTIVADOR_CULTURAL', false),
            'optante_simples_nacional' => env('FOCUS_NFE_NFSE_OPTANTE_SIMPLES_NACIONAL', true),
        ],
        'nfe' => [
            'natureza_operacao' => env('FOCUS_NFE_NFE_NATUREZA_OPERACAO', 'VENDA DE MERCADORIA'),
            'tipo_documento' => env('FOCUS_NFE_NFE_TIPO_DOCUMENTO', 1),
            'local_destino' => env('FOCUS_NFE_NFE_LOCAL_DESTINO', 1),
            'finalidade_emissao' => env('FOCUS_NFE_NFE_FINALIDADE_EMISSAO', 1),
            'consumidor_final' => env('FOCUS_NFE_NFE_CONSUMIDOR_FINAL', 1),
            'presenca_comprador' => env('FOCUS_NFE_NFE_PRESENCA_COMPRADOR', 1),
            'modalidade_frete' => env('FOCUS_NFE_NFE_MODALIDADE_FRETE', 9),
            'forma_pagamento' => env('FOCUS_NFE_NFE_FORMA_PAGAMENTO', '01'),
            'cfop_padrao' => env('FOCUS_NFE_NFE_CFOP_PADRAO', '5102'),
            'codigo_ncm_padrao' => env('FOCUS_NFE_NFE_CODIGO_NCM_PADRAO'),
            'icms_origem' => env('FOCUS_NFE_NFE_ICMS_ORIGEM', '0'),
            'icms_situacao_tributaria' => env('FOCUS_NFE_NFE_ICMS_SITUACAO_TRIBUTARIA', '102'),
            'pis_situacao_tributaria' => env('FOCUS_NFE_NFE_PIS_SITUACAO_TRIBUTARIA', '07'),
            'cofins_situacao_tributaria' => env('FOCUS_NFE_NFE_COFINS_SITUACAO_TRIBUTARIA', '07'),
            'valor_frete' => env('FOCUS_NFE_NFE_VALOR_FRETE', 0),
            'valor_seguro' => env('FOCUS_NFE_NFE_VALOR_SEGURO', 0),
            'valor_outras_despesas' => env('FOCUS_NFE_NFE_VALOR_OUTRAS_DESPESAS', 0),
            'emitente' => [
                'nome' => env('FOCUS_NFE_EMITENTE_NOME'),
                'nome_fantasia' => env('FOCUS_NFE_EMITENTE_NOME_FANTASIA'),
                'logradouro' => env('FOCUS_NFE_EMITENTE_LOGRADOURO'),
                'numero' => env('FOCUS_NFE_EMITENTE_NUMERO'),
                'bairro' => env('FOCUS_NFE_EMITENTE_BAIRRO'),
                'municipio' => env('FOCUS_NFE_EMITENTE_MUNICIPIO'),
                'uf' => env('FOCUS_NFE_EMITENTE_UF'),
                'cep' => env('FOCUS_NFE_EMITENTE_CEP'),
                'inscricao_estadual' => env('FOCUS_NFE_EMITENTE_INSCRICAO_ESTADUAL'),
                'regime_tributario' => env('FOCUS_NFE_EMITENTE_REGIME_TRIBUTARIO'),
            ],
        ],
    ],

];
