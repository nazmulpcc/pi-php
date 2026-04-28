<?php

declare(strict_types=1);

namespace Pi\Console;

final class ReplSlashCommandCompleter
{
    /**
     * @param  list<string>  $commands
     * @return list<string>
     */
    public function complete(string $input, array $commands): array
    {
        if (! str_starts_with($input, '/')) {
            return [];
        }

        $token = strtok($input, " \t");
        $prefix = is_string($token) ? $token : $input;
        $matches = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_starts_with($command, $prefix),
        ));
        sort($matches);

        return $matches;
    }
}
