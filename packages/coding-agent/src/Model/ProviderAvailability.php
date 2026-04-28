<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Model;

readonly class ProviderAvailability
{
    public function __construct(
        public string $provider,
        public int $modelCount,
        public bool $configured,
        public ?string $source = null,
        public ?string $label = null,
    ) {}
}
