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
        fflush(STDOUT);
    }

    /**
     * @param  array<string, mixed>|object|scalar|null  $payload
     */
    public function writeProtocolJson(mixed $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);
    }

    public function writeStdoutLine(string $line): void
    {
        $stream = $this->protocolMode ? STDERR : STDOUT;
        fwrite($stream, rtrim($line)."\n");
        fflush($stream);
    }

    public function writeNotice(string $message, string $type = 'info'): void
    {
        fwrite(STDERR, sprintf("[%s] %s\n", $type, $message));
        fflush(STDERR);
    }

    public function writeError(string $message): void
    {
        fwrite(STDERR, $message."\n");
        fflush(STDERR);
    }
}
