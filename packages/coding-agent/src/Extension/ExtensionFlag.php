<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final readonly class ExtensionFlag
{
    public function __construct(
        public string $name,
        public string $description,
        public string $type = 'boolean',
        public bool|string|null $default = null,
    ) {}
}
