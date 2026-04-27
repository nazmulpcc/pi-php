<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Resource;

readonly class Skill
{
    public function __construct(
        public string $name,
        public string $path,
        public string $content,
    ) {}
}
