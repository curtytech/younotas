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
        return in_array($status, [self::DRAFT, self::AUTHORIZATION_ERROR, self::TRANSPORT_ERROR, self::CANCELED], true);
    }

    /**
     * A Focus recusa uma referência já autorizada ou cancelada. Uma nota rejeitada
     * (erro_autorizacao) pode ser reenviada com a mesma referência.
     */
    public static function requiresNewReference(?string $status): bool
    {
        return $status === self::CANCELED;
    }

    /**
     * Falhas ambíguas (sem resposta, timeout, 429 ou 5xx) podem ter chegado à Focus:
     * o retry deve repetir o mesmo payload na mesma referência.
     */
    public static function isAmbiguousFailure(?array $error): bool
    {
        if (($error['operation'] ?? null) !== 'emissao') {
            return false;
        }

        $httpStatus = $error['http_status'] ?? null;

        return $httpStatus === null || $httpStatus >= 500 || in_array($httpStatus, [408, 429], true);
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
}
