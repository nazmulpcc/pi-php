<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI\Codex;

final class CodexWebSocketTransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly bool $started = false,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
