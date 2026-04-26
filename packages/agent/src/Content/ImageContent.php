<?php

declare(strict_types=1);

namespace Pi\Agent\Content;

readonly class ImageContent
{
    public function __construct(
        public string $data,
        public string $mimeType,
    ) {}
}
