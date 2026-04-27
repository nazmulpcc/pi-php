<?php

declare(strict_types=1);

namespace Pi\AI\Mistral;

use Pi\AI\CacheRetention;
use Pi\AI\CancellationToken;
use Pi\AI\StreamOptions;
use Pi\AI\Transport;

readonly class MistralOptions extends StreamOptions
{
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
        public string|array|null $toolChoice = null,
        public ?string $promptMode = null,
        public ?string $reasoningEffort = null,
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
