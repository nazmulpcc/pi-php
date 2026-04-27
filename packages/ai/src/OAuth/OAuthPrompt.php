<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

readonly class OAuthPrompt
{
    public function __construct(
        public string $message,
        public ?string $placeholder = null,
        public bool $allowEmpty = false,
    ) {}
}
