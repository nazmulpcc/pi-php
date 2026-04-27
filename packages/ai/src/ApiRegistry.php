<?php

declare(strict_types=1);

namespace Pi\AI;

final class ApiRegistry
{
    /** @var array<string, array{provider: ApiProviderInterface, sourceId: string|null}> */
    private static array $providers = [];

    public static function registerProvider(ApiProviderInterface $provider, ?string $sourceId = null): void
    {
        self::$providers[$provider->getApi()->value] = [
            'provider' => new RegisteredApiProvider($provider),
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
            }
        }
    }

    public static function clear(): void
    {
        self::$providers = [];
    }

    private static function normalizeApi(Api|string $api): string
    {
        return $api instanceof Api ? $api->value : $api;
    }
}
