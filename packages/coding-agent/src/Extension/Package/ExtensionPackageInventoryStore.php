<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

interface ExtensionPackageInventoryStore
{
    public function getPath(): string;

    public function load(): ?string;

    public function save(string $contents): void;
}
