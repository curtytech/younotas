<?php

namespace App\Services;

use App\Exceptions\FocusProtocolException;
use Illuminate\Http\Client\Response;

class FocusResponse
{
    public static function document(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data) || array_is_list($data) || ! is_string($data['status'] ?? null) || ! in_array($data['status'], [
            'autorizado', 'cancelado', 'denegado', 'processando', 'processando_autorizacao',
            'processando_cancelamento', 'enviando', 'erro_autorizacao', 'erro_cancelamento', 'erro_emissao',
        ], true)) {
            throw new FocusProtocolException;
        }

        return $data;
    }
}
