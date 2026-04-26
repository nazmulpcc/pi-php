<?php

declare(strict_types=1);

namespace Pi\Agent\Content;

readonly class TextContent
{
    public function __construct(
        public string $text,
    ) {}
}
