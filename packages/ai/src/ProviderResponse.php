<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class ProviderResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers = [],
    ) {}
}
