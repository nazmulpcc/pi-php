<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class Model
{
    /**
     * @param  array<int, string>  $input
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $compat
     */
    public function __construct(
        public string $id,
        public string $name,
        public Api $api,
        public Provider $provider,
        public string $baseUrl,
        public bool $reasoning,
        public array $input,
        public UsageCost $cost,
        public int $contextWindow,
        public int $maxTokens,
        public array $headers = [],
        public ?array $compat = null,
    ) {}
}
