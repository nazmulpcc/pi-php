<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Resource;

readonly class ContextFile
{
    public function __construct(
        public string $path,
        public string $content,
    ) {}
}
