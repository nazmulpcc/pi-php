<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final readonly class ExtensionCommand
{
    public function __construct(
        public string $name,
        public string $description,
        public \Closure $handler,
    ) {}
}
