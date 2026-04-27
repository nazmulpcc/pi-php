<?php

declare(strict_types=1);

namespace Pi\AI;

final class ApiRegistry
{
    /** @var array<string, array{provider: ApiProviderInterface, sourceId: string|null}> */
    private static array $providers = [];

    /** @var array<string, \Closure(): ApiProviderInterface> */
    private static array $factories = [];

    public static function registerProvider(ApiProviderInterface $provider, ?string $sourceId = null): void
    {
        self::$providers[$provider->getApi()->value] = [
            'provider' => new RegisteredApiProvider($provider),
            'sourceId' => $sourceId,
        ];
    }

    public static function registerProviderFactory(string $api, \Closure $factory, ?string $sourceId = null): void
    {
        self::$factories[$api] = $factory;
        self::$providers[$api] = [
            'provider' => new LazyApiProvider($api, $factory),
            'sourceId' => $sourceId,
        ];
    }

    public static function getProvider(Api|string $api): ?ApiProviderInterface
    {
        return self::$providers[self::normalizeApi($api)]['provider'] ?? null;
    }

    /**
     * @return array<int, ApiProviderInterface>
     */
    public static function getProviders(): array
    {
        return array_map(
            static fn (array $entry): ApiProviderInterface => $entry['provider'],
            array_values(self::$providers),
        );
    }

    public static function unregisterProviders(string $sourceId): void
    {
        foreach (self::$providers as $api => $entry) {
            if ($entry['sourceId'] === $sourceId) {
                unset(self::$providers[$api]);
                unset(self::$factories[$api]);
            }
        }
    }

    public static function clear(): void
    {
        self::$providers = [];
        self::$factories = [];
    }

    private static function normalizeApi(Api|string $api): string
    {
        return $api instanceof Api ? $api->value : $api;
    }
}
