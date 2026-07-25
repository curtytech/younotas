<?php

namespace App\Support;

class FiscalDocument
{
    public static function cpfIsValid(string $cpf): bool
    {
        $cpf = self::digits($cpf);

        if (! preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;

            for ($index = 0; $index < $position; $index++) {
                $sum += ((int) $cpf[$index]) * (($position + 1) - $index);
            }

            if ((int) $cpf[$position] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjIsValid(string $cnpj): bool
    {
        $cnpj = self::digits($cnpj);

        if (! preg_match('/^\d{14}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $position => $weights) {
            $sum = 0;

            foreach ($weights as $index => $weight) {
                $sum += ((int) $cnpj[$index]) * $weight;
            }

            $digit = $sum % 11;
            $digit = $digit < 2 ? 0 : 11 - $digit;

            if ((int) $cnpj[12 + $position] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
