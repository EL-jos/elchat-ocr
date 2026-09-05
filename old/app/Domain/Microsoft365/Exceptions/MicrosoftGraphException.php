<?php

namespace App\Domain\Microsoft365\Exceptions;

use RuntimeException;

final class MicrosoftGraphException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $graphCode = null,
    ) {
        parent::__construct($message, $status);
    }

    public function isAuthFailure(): bool
    {
        return $this->status === 401;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
