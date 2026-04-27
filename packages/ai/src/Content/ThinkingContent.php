<?php

declare(strict_types=1);

namespace Pi\AI\Content;

readonly class ThinkingContent
{
    public function __construct(
        public string $thinking,
        public ?string $thinkingSignature = null,
        public bool $redacted = false,
    ) {}
}
