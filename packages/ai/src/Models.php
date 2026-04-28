<?php

declare(strict_types=1);

namespace Pi\AI;

final class Models
{
    /** @var array<string, array<string, Model>> */
    private static array $registry = [];

    /** @var array<string, array<string, array<string, Model>>> */
    private static array $dynamicRegistry = [];

    private static bool $loaded = false;

    public static function getModel(Provider|string $provider, string $modelId): ?Model
    {
        self::ensureLoaded();

        $providerKey = self::providerKey($provider);
        foreach (self::$dynamicRegistry as $sourceModels) {
            if (isset($sourceModels[$providerKey][$modelId])) {
                return $sourceModels[$providerKey][$modelId];
            }
        }

        return self::$registry[$providerKey][$modelId] ?? null;
    }

    /**
     * @return array<int, Provider>
     */
    public static function getProviders(): array
    {
        self::ensureLoaded();

        $providers = array_fill_keys(array_keys(self::$registry), true);
        foreach (self::$dynamicRegistry as $sourceModels) {
            foreach (array_keys($sourceModels) as $provider) {
                $providers[$provider] = true;
            }
        }

        return array_map(static fn (string $provider): Provider => new Provider($provider), array_keys($providers));
    }

    /**
     * @return array<int, Model>
     */
    public static function getModels(Provider|string $provider): array
    {
        self::ensureLoaded();

        $providerKey = self::providerKey($provider);
        $models = self::$registry[$providerKey] ?? [];

        foreach (self::$dynamicRegistry as $sourceModels) {
            foreach (($sourceModels[$providerKey] ?? []) as $modelId => $model) {
                $models[$modelId] = $model;
            }
        }

        return array_values($models);
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

    /**
     * @param  array<Model>  $models
     */
    public static function registerDynamicModels(string $sourceId, array $models): void
    {
        self::ensureLoaded();
        self::unregisterDynamicModels($sourceId);

        foreach ($models as $model) {
            self::$dynamicRegistry[$sourceId][$model->provider->value][$model->id] = $model;
        }
    }

    public static function unregisterDynamicModels(string $sourceId): void
    {
        unset(self::$dynamicRegistry[$sourceId]);
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
