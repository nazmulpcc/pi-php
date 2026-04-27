<?php

declare(strict_types=1);

namespace Pi\AI\Content;

readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
        public ?string $thoughtSignature = null,
    ) {}
}
