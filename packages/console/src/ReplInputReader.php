<?php

declare(strict_types=1);

namespace Pi\Console;

final class ReplInputReader
{
    /**
     * @param  callable(string): list<string>  $complete
     */
    public function __construct(
        private readonly ConsoleOutputGuard $outputGuard,
        private readonly mixed $complete,
    ) {}

    public function readLine(string $prompt): ?string
    {
        if ($this->supportsReadline()) {
            $this->registerCompletion();
            $line = readline($prompt);
            if ($line === false) {
                return null;
            }

            $line = trim($line);
            if ($line !== '' && function_exists('readline_add_history')) {
                readline_add_history($line);
            }

            return $line;
        }

        $this->outputGuard->writeProtocolLine($prompt);
        $line = fgets(STDIN);
        if ($line === false) {
            return null;
        }

        return trim($line);
    }

    /**
     * @param  list<string>  $history
     */
    public function seedHistory(array $history): void
    {
        if (! $this->supportsReadline() || ! function_exists('readline_add_history')) {
            return;
        }

        foreach ($history as $line) {
            $line = trim($line);
            if ($line !== '') {
                readline_add_history($line);
            }
        }
    }

    public function supportsReadline(): bool
    {
        return function_exists('readline')
            && function_exists('readline_completion_function')
            && function_exists('posix_isatty')
            && posix_isatty(STDIN);
    }

    private function registerCompletion(): void
    {
        readline_completion_function(function (string $input): array {
            $complete = $this->complete;

            return $complete($input);
        });
    }
}
