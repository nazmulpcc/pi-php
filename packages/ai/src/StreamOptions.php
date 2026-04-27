<?php

declare(strict_types=1);

namespace Pi\AI;

use Closure;

readonly class StreamOptions
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?CancellationToken $signal = null,
        public ?string $apiKey = null,
        public ?Transport $transport = null,
        public CacheRetention $cacheRetention = CacheRetention::Short,
        public ?string $sessionId = null,
        public ?Closure $onPayload = null,
        public ?Closure $onResponse = null,
        public array $headers = [],
        public ?int $timeoutMs = null,
        public ?int $maxRetries = null,
        public ?int $maxRetryDelayMs = null,
        public array $metadata = [],
    ) {}
}
