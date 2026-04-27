<?php

declare(strict_types=1);

namespace Pi\AI\Compat;

readonly class OpenAIResponsesCompat
{
    public function __construct(
        public ?bool $sendSessionIdHeader = null,
        public ?bool $supportsLongCacheRetention = null,
    ) {}
}
