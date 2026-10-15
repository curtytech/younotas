<?php

namespace App\Exceptions;

use RuntimeException;

class FocusProtocolException extends RuntimeException
{
    public function __construct(public readonly int $status = 200)
    {
        parent::__construct('A Focus retornou uma resposta inválida. Consulte a referência antes de repetir a emissão.', $status);
    }

    public function mayHaveBeenProcessed(): bool
    {
        return true;
    }
}
