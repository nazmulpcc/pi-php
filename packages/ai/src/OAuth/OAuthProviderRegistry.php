<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

use Pi\AI\Support\PromiseHelper;
use React\Promise\PromiseInterface;

final class OAuthProviderRegistry
{
    /** @var array<string, OAuthProviderInterface>|null */
    private static ?array $providers = null;

    /** @var array<string, OAuthProviderInterface>|null */
    private static ?array $builtIns = null;

    public static function get(string $id): ?OAuthProviderInterface
    {
        self::boot();

        return self::$providers[$id] ?? null;
    }

    /**
     * @return array<OAuthProviderInterface>
     */
    public static function all(): array
    {
        self::boot();

        return array_values(self::$providers);
    }

    public static function register(OAuthProviderInterface $provider): void
    {
        self::boot();
        self::$providers[$provider->getId()] = $provider;
    }

    public static function unregister(string $id): void
    {
        self::boot();
        if (isset(self::$builtIns[$id])) {
            self::$providers[$id] = self::$builtIns[$id];

            return;
        }

        unset(self::$providers[$id]);
    }

    public static function reset(): void
    {
        self::boot();
        self::$providers = self::$builtIns;
    }

    /**
     * @param  array<string, OAuthCredentials|array<string, mixed>>  $credentials
     * @return PromiseInterface<array{newCredentials: OAuthCredentials, apiKey: string}|null>
     */
    public static function getApiKey(string $providerId, array $credentials): PromiseInterface
    {
        $provider = self::get($providerId);
        if ($provider === null || ! isset($credentials[$providerId])) {
            return PromiseHelper::resolve(null);
        }

        $cred = $credentials[$providerId];
        $oauthCredentials = $cred instanceof OAuthCredentials ? $cred : OAuthCredentials::fromArray($cred);

        if (time() * 1000 < $oauthCredentials->expires) {
            return PromiseHelper::resolve([
                'newCredentials' => $oauthCredentials,
                'apiKey' => $provider->getApiKey($oauthCredentials),
            ]);
        }

        return $provider->refreshToken($oauthCredentials)
            ->then(static fn (OAuthCredentials $refreshed): array => [
                'newCredentials' => $refreshed,
                'apiKey' => $provider->getApiKey($refreshed),
            ]);
    }

    private static function boot(): void
    {
        if (self::$providers !== null) {
            return;
        }

        self::$builtIns = [
            'anthropic' => new Providers\AnthropicOAuthProvider,
            'github-copilot' => new Providers\GitHubCopilotOAuthProvider,
            'google-gemini-cli' => new Providers\GoogleGeminiCliOAuthProvider,
            'google-antigravity' => new Providers\GoogleAntigravityOAuthProvider,
            'openai-codex' => new Providers\OpenAICodexOAuthProvider,
        ];
        self::$providers = self::$builtIns;
    }
}
