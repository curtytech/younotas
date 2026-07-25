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
}
