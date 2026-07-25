<?php

namespace App\Exceptions;

use RuntimeException;

class FocusNfseRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?array $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function mayHaveBeenProcessed(): bool
    {
        return $this->status >= 500 || $this->status === 422;
    }
}
