<?php

declare(strict_types=1);

namespace Pi\Console;

final class ConsoleOutputGuard
{
    public function __construct(
        private readonly bool $protocolMode = false,
    ) {}

    public function isProtocolMode(): bool
    {
        return $this->protocolMode;
    }

    public function writeProtocolLine(string $line): void
    {
        fwrite(STDOUT, $line);
    }

    /**
     * @param  array<string, mixed>|object|scalar|null  $payload
     */
    public function writeProtocolJson(mixed $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR)."\n");
    }

    public function writeStdoutLine(string $line): void
    {
        fwrite($this->protocolMode ? STDERR : STDOUT, rtrim($line)."\n");
    }

    public function writeNotice(string $message, string $type = 'info'): void
    {
        fwrite(STDERR, sprintf("[%s] %s\n", $type, $message));
    }

    public function writeError(string $message): void
    {
        fwrite(STDERR, $message."\n");
    }
}
