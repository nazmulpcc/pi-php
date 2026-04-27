<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

readonly class OAuthAuthInfo
{
    public function __construct(
        public string $url,
        public ?string $instructions = null,
    ) {}
}
