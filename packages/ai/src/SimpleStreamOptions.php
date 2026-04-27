<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class SimpleStreamOptions extends StreamOptions
{
    /**
     * @param  array<string, int>  $thinkingBudgets
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?CancellationToken $signal = null,
        ?string $apiKey = null,
        ?Transport $transport = null,
        CacheRetention $cacheRetention = CacheRetention::Short,
        ?string $sessionId = null,
        ?\Closure $onPayload = null,
        ?\Closure $onResponse = null,
        array $headers = [],
        ?int $timeoutMs = null,
        ?int $maxRetries = null,
        ?int $maxRetryDelayMs = null,
        array $metadata = [],
        public ?ThinkingLevel $reasoning = null,
        public array $thinkingBudgets = [],
    ) {
        parent::__construct(
            temperature: $temperature,
            maxTokens: $maxTokens,
            signal: $signal,
            apiKey: $apiKey,
            transport: $transport,
            cacheRetention: $cacheRetention,
            sessionId: $sessionId,
            onPayload: $onPayload,
            onResponse: $onResponse,
            headers: $headers,
            timeoutMs: $timeoutMs,
            maxRetries: $maxRetries,
            maxRetryDelayMs: $maxRetryDelayMs,
            metadata: $metadata,
        );
    }
}
