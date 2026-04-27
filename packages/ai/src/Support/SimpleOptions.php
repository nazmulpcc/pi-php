<?php

declare(strict_types=1);

namespace Pi\AI\Support;

use Pi\AI\CacheRetention;
use Pi\AI\Model;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StreamOptions;
use Pi\AI\ThinkingLevel;

final class SimpleOptions
{
    public static function buildBaseOptions(Model $model, ?SimpleStreamOptions $options = null, ?string $apiKey = null): StreamOptions
    {
        return new StreamOptions(
            temperature: $options?->temperature,
            maxTokens: $options?->maxTokens ?? ($model->maxTokens > 0 ? min($model->maxTokens, 32000) : null),
            signal: $options?->signal,
            apiKey: $apiKey ?? $options?->apiKey,
            transport: $options?->transport,
            cacheRetention: $options?->cacheRetention ?? CacheRetention::Short,
            sessionId: $options?->sessionId,
            onPayload: $options?->onPayload,
            onResponse: $options?->onResponse,
            headers: $options?->headers ?? [],
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

    /**
     * @param  array<string, int>  $customBudgets
     * @return array{maxTokens: int, thinkingBudget: int}
     */
    public static function adjustMaxTokensForThinking(int $baseMaxTokens, int $modelMaxTokens, ?ThinkingLevel $reasoningLevel, array $customBudgets = []): array
    {
        $defaultBudgets = [
            'minimal' => 1024,
            'low' => 2048,
            'medium' => 8192,
            'high' => 16384,
        ];
        $budgets = array_merge($defaultBudgets, $customBudgets);

        $minOutputTokens = 1024;
        $level = self::clampReasoning($reasoningLevel)?->value;
        if ($level === null) {
            $level = 'medium';
        }
        $thinkingBudget = $budgets[$level] ?? $defaultBudgets['medium'];
        $maxTokens = min($baseMaxTokens + $thinkingBudget, $modelMaxTokens);

        if ($maxTokens <= $thinkingBudget) {
            $thinkingBudget = max(0, $maxTokens - $minOutputTokens);
        }

        return [
            'maxTokens' => $maxTokens,
            'thinkingBudget' => $thinkingBudget,
        ];
    }
}
