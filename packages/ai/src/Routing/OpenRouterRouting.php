<?php

declare(strict_types=1);

namespace Pi\AI\Routing;

readonly class OpenRouterRouting
{
    /**
     * @param  array<int, string>  $order
     * @param  array<int, string>  $only
     * @param  array<int, string>  $ignore
     * @param  array<int, string>  $quantizations
     * @param  array<string, mixed>|null  $maxPrice
     * @param  array<string, mixed>|null  $preferredMinThroughput
     * @param  array<string, mixed>|null  $preferredMaxLatency
     */
    public function __construct(
        public ?bool $allowFallbacks = null,
        public ?bool $requireParameters = null,
        public ?string $dataCollection = null,
        public ?bool $zdr = null,
        public ?bool $enforceDistillableText = null,
        public array $order = [],
        public array $only = [],
        public array $ignore = [],
        public array $quantizations = [],
        public string|array|null $sort = null,
        public ?array $maxPrice = null,
        public float|array|null $preferredMinThroughput = null,
        public float|array|null $preferredMaxLatency = null,
    ) {}
}
