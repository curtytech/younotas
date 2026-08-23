<?php

namespace App\Support;

class NfseStatus
{
    public const DRAFT = null;

    public const SENDING = 'enviando';

    public const PROCESSING = 'processando';

    public const AUTHORIZED = 'autorizado';

    public const CANCELED = 'cancelado';

    public const AUTHORIZATION_ERROR = 'erro_autorizacao';

    public const CANCELLATION_ERROR = 'erro_cancelamento';

    public const TRANSPORT_ERROR = 'erro_emissao';

    public static function normalize(?string $status): ?string
    {
        return match ($status) {
            'processando', 'processando_autorizacao', 'enviando' => self::PROCESSING,
            'autorizado' => self::AUTHORIZED,
            'cancelado' => self::CANCELED,
            'erro_autorizacao' => self::AUTHORIZATION_ERROR,
            'erro_cancelamento' => self::CANCELLATION_ERROR,
            'erro_emissao' => self::TRANSPORT_ERROR,
            default => $status,
        };
    }

    public static function canEmit(?string $status): bool
    {
        return in_array($status, [self::DRAFT, self::AUTHORIZATION_ERROR, self::TRANSPORT_ERROR], true);
    }

    public static function canCancel(?string $status): bool
    {
        return $status === self::AUTHORIZED;
    }

    public static function shouldReplace(?string $current, ?string $incoming): bool
    {
        $incoming = self::normalize($incoming);

        if (blank($incoming) || $current === $incoming) {
            return false;
        }

        if ($current === self::CANCELED) {
            return false;
        }

        if ($current === self::AUTHORIZED) {
            return $incoming === self::CANCELED;
        }

        return true;
    }

    public static function isProcessingTimeout(array $response): bool
    {
        return self::errorCode($response) === 'tempo_excedido';
    }

    public static function errorCode(array $response): ?string
    {
        foreach ([
            data_get($response, 'codigo'),
            data_get($response, 'erro.codigo'),
            data_get($response, 'erros.0.codigo'),
            data_get($response, 'mensagens.0.codigo'),
        ] as $code) {
            if (is_scalar($code) && filled($code)) {
                return mb_strtolower(trim((string) $code));
            }
        }

        return null;
    }
}
