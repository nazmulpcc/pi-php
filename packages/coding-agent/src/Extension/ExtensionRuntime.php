<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final class ExtensionRuntime
{
    /** @var array<string, bool|string> */
    private array $flagValues = [];

    private int $version = 0;

    /** @var array<string, mixed> */
    private array $actions = [];

    public function setFlagValue(string $name, bool|string $value): void
    {
        $this->flagValues[$name] = $value;
    }

    /**
     * @param  array<string, bool|string>  $values
     */
    public function setFlagValues(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->flagValues[$name] = $value;
        }
    }

    public function getFlagValue(string $name): bool|string|null
    {
        return $this->flagValues[$name] ?? null;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function invalidate(): void
    {
        $this->version++;
    }

    /**
     * @param  array<string, mixed>  $actions
     */
    public function bindActions(array $actions): void
    {
        $this->actions = $actions;
    }

    public function action(string $name): mixed
    {
        if (! array_key_exists($name, $this->actions)) {
            throw new \RuntimeException(sprintf('Extension runtime action not initialized: %s', $name));
        }

        return $this->actions[$name];
    }
}
