<?php

declare(strict_types=1);

namespace Pi\AI\Bedrock;

use Pi\AI\CacheRetention;
use Pi\AI\CancellationToken;
use Pi\AI\StreamOptions;
use Pi\AI\ThinkingLevel;
use Pi\AI\Transport;

readonly class BedrockOptions extends StreamOptions
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $metadata
     * @param  array<string, int>  $thinkingBudgets
     * @param  array<string, string>  $requestMetadata
     * @param  string|array<string, string>|null  $toolChoice
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
        public ?string $region = null,
        public ?string $profile = null,
        public string|array|null $toolChoice = null,
        public ?ThinkingLevel $reasoning = null,
        public array $thinkingBudgets = [],
        public ?bool $interleavedThinking = null,
        public ?string $thinkingDisplay = null,
        public array $requestMetadata = [],
        public ?string $bearerToken = null,
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
