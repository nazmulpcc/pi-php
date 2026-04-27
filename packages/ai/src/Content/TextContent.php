<?php

declare(strict_types=1);

namespace Pi\AI\Content;

readonly class TextContent
{
    public function __construct(
        public string $text,
        public ?string $textSignature = null,
    ) {}
}
