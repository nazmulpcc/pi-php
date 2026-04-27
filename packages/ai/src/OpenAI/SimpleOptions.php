<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI;

use Pi\AI\CacheRetention;
use Pi\AI\Model;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\ThinkingLevel;

final class SimpleOptions
{
    public static function buildBaseOptions(Model $model, ?SimpleStreamOptions $options = null, ?string $apiKey = null): OpenAIResponsesOptions
    {
        return new OpenAIResponsesOptions(
            temperature: $options?->temperature,
            maxTokens: $options?->maxTokens ?? ($model->maxTokens > 0 ? min($model->maxTokens, 32000) : null),
            signal: $options?->signal,
            apiKey: $apiKey ?? $options?->apiKey,
            cacheRetention: $options?->cacheRetention ?? CacheRetention::Short,
            sessionId: $options?->sessionId,
            headers: $options?->headers ?? [],
            onPayload: $options?->onPayload,
            onResponse: $options?->onResponse,
            timeoutMs: $options?->timeoutMs,
            maxRetries: $options?->maxRetries,
            maxRetryDelayMs: $options?->maxRetryDelayMs,
            metadata: $options?->metadata ?? [],
        );
    }

    public static function clampReasoning(?ThinkingLevel $effort): ?ThinkingLevel
    {
        return $effort === ThinkingLevel::Xhigh ? ThinkingLevel::High : $effort;
    }
}
