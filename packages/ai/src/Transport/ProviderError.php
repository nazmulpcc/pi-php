<?php

declare(strict_types=1);

namespace Pi\AI\Transport;

use RuntimeException;

class ProviderError extends RuntimeException
{
    public function __construct(
        string $message,
        public int $status = 0,
        public ?string $errorType = null,
        public ?string $errorCode = null,
        public ?string $rawBody = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
