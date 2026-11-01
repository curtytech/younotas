<?php

namespace App\Support;

class BrazilianStates
{
    /** @var array<int, string> */
    public const ALL = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
        'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function normalize(?string $state): ?string
    {
        $normalized = strtoupper(trim((string) $state));

        return $normalized === '' ? null : $normalized;
    }

    public static function isValid(?string $state): bool
    {
        return in_array(self::normalize($state), self::ALL, true);
    }
}
