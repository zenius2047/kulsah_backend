<?php

namespace App\Exceptions;

use RuntimeException;

class MusicProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 502,
        private readonly ?int $retryAfterSeconds = null,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function context(): array
    {
        return $this->context;
    }
}
