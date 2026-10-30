<?php

namespace App\Support;

class NfeStatus
{
    public const SENDING = 'enviando';

    public const PROCESSING = 'processando';

    public const AUTHORIZED = 'autorizado';

    public const CANCELED = 'cancelado';

    public const DENIED = 'denegado';

    public const AUTHORIZATION_ERROR = 'erro_autorizacao';

    public const CANCELLATION_ERROR = 'erro_cancelamento';

    public const TRANSPORT_ERROR = 'erro_emissao';

    public static function normalize(?string $status): ?string
    {
        return match ($status) {
            'autorizado' => self::AUTHORIZED,
            'cancelado' => self::CANCELED,
            'denegado' => self::DENIED,
            'processando', 'processando_autorizacao', 'enviando' => self::PROCESSING,
            'erro_autorizacao' => self::AUTHORIZATION_ERROR,
            'erro_cancelamento' => self::CANCELLATION_ERROR,
            'erro_emissao' => self::TRANSPORT_ERROR,
            default => $status,
        };
    }

    public static function canEmit(?string $status): bool
    {
        return in_array($status, [null, self::AUTHORIZATION_ERROR, self::TRANSPORT_ERROR, self::CANCELED, self::DENIED], true);
    }

    /**
     * A Focus recusa uma referência já autorizada, cancelada ou denegada ("already_processed").
     * Uma nota rejeitada (erro_autorizacao) pode ser reenviada com a mesma referência.
     */
    public static function requiresNewReference(?string $status): bool
    {
        return in_array($status, [self::CANCELED, self::DENIED], true);
    }

    /**
     * Falhas ambíguas (sem resposta, timeout, 429 ou 5xx) podem ter chegado à Focus:
     * o retry deve repetir o mesmo payload na mesma referência.
     */
    public static function isAmbiguousFailure(?array $error): bool
    {
        return NfseStatus::isAmbiguousFailure($error);
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::SENDING => 'Enviando',
            self::PROCESSING => 'Processando',
            self::AUTHORIZED => 'Autorizada',
            self::CANCELED => 'Cancelada',
            self::DENIED => 'Denegada',
            self::AUTHORIZATION_ERROR => 'Rejeitada',
            self::CANCELLATION_ERROR => 'Erro no cancelamento',
            self::TRANSPORT_ERROR => 'Erro de envio',
            default => 'Não emitida',
        };
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            self::AUTHORIZED => 'success',
            self::SENDING, self::PROCESSING => 'warning',
            self::AUTHORIZATION_ERROR, self::TRANSPORT_ERROR, self::DENIED, self::CANCELLATION_ERROR => 'danger',
            default => 'gray',
        };
    }

    public static function icon(?string $status): string
    {
        return match ($status) {
            self::AUTHORIZED => 'heroicon-m-check-badge',
            self::SENDING, self::PROCESSING => 'heroicon-m-clock',
            self::CANCELED => 'heroicon-m-no-symbol',
            self::AUTHORIZATION_ERROR, self::TRANSPORT_ERROR, self::DENIED, self::CANCELLATION_ERROR => 'heroicon-m-exclamation-triangle',
            default => 'heroicon-m-minus-circle',
        };
    }

    public static function hasProblem(?string $status): bool
    {
        return in_array($status, [self::AUTHORIZATION_ERROR, self::TRANSPORT_ERROR, self::DENIED, self::CANCELLATION_ERROR], true);
    }

    public static function canCancel(?string $status): bool
    {
        return $status === self::AUTHORIZED;
    }

    public static function shouldReplace(?string $current, ?string $incoming): bool
    {
        $incoming = self::normalize($incoming);

        if (blank($incoming) || $current === $incoming || in_array($current, [self::CANCELED, self::DENIED], true)) {
            return false;
        }

        return $current !== self::AUTHORIZED || $incoming === self::CANCELED;
    }
}
