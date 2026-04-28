<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final readonly class FileExtensionPackageInventoryStore implements ExtensionPackageInventoryStore
{
    public function __construct(
        private string $path,
    ) {}

    public function getPath(): string
    {
        return $this->path;
    }

    public function load(): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        return is_string($contents) ? $contents : null;
    }

    public function save(string $contents): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path, $contents);
    }
}
