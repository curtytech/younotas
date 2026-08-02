<?php

namespace App\Exceptions;

use RuntimeException;

class FocusRateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct("Limite de requisições da Focus atingido. Tente novamente em {$retryAfterSeconds} segundo(s).");
    }
}
