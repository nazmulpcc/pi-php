<?php

declare(strict_types=1);

namespace Pi\AI;

final class Models
{
    /** @var array<string, array<string, Model>> */
    private static array $registry = [];

    private static bool $loaded = false;

    public static function getModel(Provider|string $provider, string $modelId): ?Model
    {
        self::ensureLoaded();

        return self::$registry[self::providerKey($provider)][$modelId] ?? null;
    }

    /**
     * @return array<int, Provider>
     */
    public static function getProviders(): array
    {
        self::ensureLoaded();

        return array_map(static fn (string $provider): Provider => new Provider($provider), array_keys(self::$registry));
    }

    /**
     * @return array<int, Model>
     */
    public static function getModels(Provider|string $provider): array
    {
        self::ensureLoaded();

        return array_values(self::$registry[self::providerKey($provider)] ?? []);
    }

    public static function calculateCost(Model $model, Usage $usage): UsageCost
    {
        $input = ($model->cost->input / 1_000_000) * $usage->input;
        $output = ($model->cost->output / 1_000_000) * $usage->output;
        $cacheRead = ($model->cost->cacheRead / 1_000_000) * $usage->cacheRead;
        $cacheWrite = ($model->cost->cacheWrite / 1_000_000) * $usage->cacheWrite;

        return new UsageCost(
            input: $input,
            output: $output,
            cacheRead: $cacheRead,
            cacheWrite: $cacheWrite,
            total: $input + $output + $cacheRead + $cacheWrite,
        );
    }

    public static function supportsXhigh(Model $model): bool
    {
        if (
            str_contains($model->id, 'gpt-5.2') ||
            str_contains($model->id, 'gpt-5.3') ||
            str_contains($model->id, 'gpt-5.4') ||
            str_contains($model->id, 'gpt-5.5') ||
            str_contains($model->id, 'deepseek-v4-pro')
        ) {
            return true;
        }

        if (
            str_contains($model->id, 'opus-4-6') ||
            str_contains($model->id, 'opus-4.6') ||
            str_contains($model->id, 'opus-4-7') ||
            str_contains($model->id, 'opus-4.7')
        ) {
            return true;
        }

        return false;
    }

    public static function modelsAreEqual(?Model $a, ?Model $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return $a->id === $b->id && $a->provider->equals($b->provider);
    }

    public static function reload(): void
    {
        self::$registry = [];
        self::$loaded = false;
        self::ensureLoaded();
    }

    private static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }

        $catalog = loadGeneratedModels();

        foreach ($catalog as $provider => $models) {
            $providerKey = (string) $provider;
            foreach ($models as $modelId => $definition) {
                self::$registry[$providerKey][$modelId] = self::hydrateModel($definition);
            }
        }

        self::$loaded = true;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function hydrateModel(array $definition): Model
    {
        $cost = $definition['cost'] ?? [];

        return new Model(
            id: (string) $definition['id'],
            name: (string) $definition['name'],
            api: new Api((string) $definition['api']),
            provider: new Provider((string) $definition['provider']),
            baseUrl: (string) $definition['baseUrl'],
            reasoning: (bool) $definition['reasoning'],
            input: $definition['input'],
            cost: new UsageCost(
                input: (float) ($cost['input'] ?? 0.0),
                output: (float) ($cost['output'] ?? 0.0),
                cacheRead: (float) ($cost['cacheRead'] ?? 0.0),
                cacheWrite: (float) ($cost['cacheWrite'] ?? 0.0),
            ),
            contextWindow: (int) $definition['contextWindow'],
            maxTokens: (int) $definition['maxTokens'],
            headers: $definition['headers'] ?? [],
            compat: $definition['compat'] ?? null,
        );
    }

    private static function providerKey(Provider|string $provider): string
    {
        return $provider instanceof Provider ? $provider->value : $provider;
    }
}
