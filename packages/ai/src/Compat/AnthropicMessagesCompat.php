<?php

declare(strict_types=1);

namespace Pi\AI\Compat;

readonly class AnthropicMessagesCompat
{
    public function __construct(
        public ?bool $supportsEagerToolInputStreaming = null,
        public ?bool $supportsLongCacheRetention = null,
    ) {}
}
