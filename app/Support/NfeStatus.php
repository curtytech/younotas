<?php

namespace App\Support;

class NfeStatus
{
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
            'autorizado' => self::AUTHORIZED,
            'cancelado' => self::CANCELED,
            'processando', 'enviando' => self::PROCESSING,
            'erro_autorizacao' => self::AUTHORIZATION_ERROR,
            'erro_cancelamento' => self::CANCELLATION_ERROR,
            default => $status,
        };
    }

    public static function canEmit(?string $status): bool
    {
        return in_array($status, [null, self::AUTHORIZATION_ERROR, self::TRANSPORT_ERROR], true);
    }

    public static function canCancel(?string $status): bool
    {
        return $status === self::AUTHORIZED;
    }

    public static function shouldReplace(?string $current, ?string $incoming): bool
    {
        $incoming = self::normalize($incoming);

        if (blank($incoming) || $current === $incoming || $current === self::CANCELED) {
            return false;
        }

        return $current !== self::AUTHORIZED || $incoming === self::CANCELED;
    }
}
