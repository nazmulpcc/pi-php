<?php

declare(strict_types=1);

namespace Pi\Agent\Content;

readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?string $thoughtSignature = null,
    ) {}
}
