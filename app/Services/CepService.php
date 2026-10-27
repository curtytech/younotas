<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CepService
{
    public function addressFor(?string $cep): ?array
    {
        $cep = preg_replace('/\D+/', '', (string) $cep);

        if (! is_string($cep) || ! preg_match('/^\d{8}$/', $cep)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get("https://viacep.com.br/ws/{$cep}/json/");
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $address = $response->json();

        if (! is_array($address) || ($address['erro'] ?? false) === true) {
            return null;
        }

        return [
            'address' => $this->stringValue($address['logradouro'] ?? null),
            'neighborhood' => $this->stringValue($address['bairro'] ?? null),
            'city' => $this->stringValue($address['localidade'] ?? null),
            'state' => $this->stringValue($address['uf'] ?? null),
            'ibge_code' => $this->validIbgeCode($address['ibge'] ?? null),
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function validIbgeCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{7}$/', $value) === 1 ? $value : null;
    }
}
