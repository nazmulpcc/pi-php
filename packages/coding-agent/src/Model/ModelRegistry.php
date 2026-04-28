<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Model;

use Pi\AI\Model;
use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\Settings\SettingsManager;

use function Pi\AI\getModel;
use function Pi\AI\getModels;
use function Pi\AI\getProviders;

final class ModelRegistry
{
    public function __construct(
        private readonly ?AuthStorage $authStorage = null,
        private readonly ?SettingsManager $settingsManager = null,
    ) {}

    /**
     * @return array<Model>
     */
    public function getAvailableModels(): array
    {
        $models = [];
        foreach (getProviders() as $provider) {
            foreach (getModels($provider) as $model) {
                $models[] = $model;
            }
        }

        if ($this->authStorage !== null) {
            $models = $this->authStorage->modifyModels($models);
        }

        usort($models, static function (Model $left, Model $right): int {
            $providerComparison = strcmp($left->provider->value, $right->provider->value);
            if ($providerComparison !== 0) {
                return $providerComparison;
            }

            return strcmp($left->id, $right->id);
        });

        return $models;
    }

    /**
     * @return array<ProviderAvailability>
     */
    public function getProviderAvailability(): array
    {
        $modelsByProvider = [];
        foreach ($this->getAvailableModels() as $model) {
            $modelsByProvider[$model->provider->value] ??= 0;
            $modelsByProvider[$model->provider->value]++;
        }

        $providers = array_keys($modelsByProvider);
        sort($providers);

        $availability = [];
        foreach ($providers as $provider) {
            $status = $this->authStorage?->getStatus($provider) ?? ['configured' => false];
            $availability[] = new ProviderAvailability(
                provider: $provider,
                modelCount: $modelsByProvider[$provider] ?? 0,
                configured: (bool) ($status['configured'] ?? false),
                source: is_string($status['source'] ?? null) ? $status['source'] : null,
                label: is_string($status['label'] ?? null) ? $status['label'] : null,
            );
        }

        return $availability;
    }

    /**
     * @return array<Model>
     */
    public function getUsableModels(): array
    {
        $usableProviders = [];
        foreach ($this->getProviderAvailability() as $availability) {
            if ($availability->configured) {
                $usableProviders[$availability->provider] = true;
            }
        }

        return array_values(array_filter(
            $this->getAvailableModels(),
            static fn (Model $model): bool => isset($usableProviders[$model->provider->value]),
        ));
    }

    public function isModelUsable(Model $model): bool
    {
        foreach ($this->getProviderAvailability() as $availability) {
            if ($availability->provider === $model->provider->value) {
                return $availability->configured;
            }
        }

        return false;
    }

    public function resolve(?Model $explicitModel = null, ?string $provider = null, ?string $modelId = null): ResolvedModelSelection
    {
        if ($explicitModel instanceof Model) {
            return new ResolvedModelSelection(
                model: $explicitModel,
                provider: $explicitModel->provider->value,
                modelId: $explicitModel->id,
                source: 'explicit-model',
            );
        }

        $requestedProvider = $provider;
        $requestedModelId = $modelId;

        if ($requestedProvider !== null && $requestedModelId !== null) {
            return new ResolvedModelSelection(
                model: $this->findModel($requestedProvider, $requestedModelId),
                provider: $requestedProvider,
                modelId: $requestedModelId,
                source: 'explicit-cli',
            );
        }

        $settingsProvider = $requestedProvider ?? $this->settingsManager?->getDefaultProvider();
        $settingsModelId = $requestedModelId ?? $this->settingsManager?->getDefaultModel();

        if ($settingsProvider !== null && $settingsModelId !== null) {
            return new ResolvedModelSelection(
                model: $this->findModel($settingsProvider, $settingsModelId),
                provider: $settingsProvider,
                modelId: $settingsModelId,
                source: 'settings-defaults',
            );
        }

        if ($settingsProvider !== null) {
            $models = $this->getProviderModels($settingsProvider);
            $model = $models[0] ?? null;

            return new ResolvedModelSelection(
                model: $model,
                provider: $settingsProvider,
                modelId: $model?->id,
                source: 'settings-provider-default',
            );
        }

        if ($settingsModelId !== null) {
            $matches = array_values(array_filter(
                $this->getAvailableModels(),
                static fn (Model $model): bool => $model->id === $settingsModelId,
            ));
            $model = count($matches) === 1 ? $matches[0] : null;

            return new ResolvedModelSelection(
                model: $model,
                provider: $model?->provider->value,
                modelId: $settingsModelId,
                source: 'settings-model-default',
            );
        }

        return new ResolvedModelSelection(
            model: null,
            provider: null,
            modelId: null,
            source: null,
        );
    }

    public function findModel(string $provider, string $modelId): ?Model
    {
        foreach ($this->getProviderModels($provider) as $model) {
            if ($model->id === $modelId) {
                return $model;
            }
        }

        $model = getModel($provider, $modelId);
        if (! $model instanceof Model) {
            return null;
        }

        $matches = array_values(array_filter(
            $this->getAvailableModels(),
            static fn (Model $candidate): bool => $candidate->provider->value === $provider && $candidate->id === $modelId,
        ));

        return $matches[0] ?? $model;
    }

    /**
     * @return array<Model>
     */
    public function getProviderModels(string $provider): array
    {
        return array_values(array_filter(
            $this->getAvailableModels(),
            static fn (Model $model): bool => $model->provider->value === $provider,
        ));
    }
}
