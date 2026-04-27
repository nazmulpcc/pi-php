<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Settings;

final class InMemorySettingsStorage implements SettingsStorage
{
    /** @var array<string, string> */
    private array $values = [];

    public function read(string $scope): ?string
    {
        return $this->values[$scope] ?? null;
    }

    public function write(string $scope, string $content): void
    {
        $this->values[$scope] = $content;
    }
}
