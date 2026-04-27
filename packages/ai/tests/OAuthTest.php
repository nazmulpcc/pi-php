<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Model;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use Pi\AI\OAuth\OAuthProviderInterface;
use React\Promise\PromiseInterface;

use function Pi\AI\getModel;
use function Pi\AI\getOAuthApiKey;
use function Pi\AI\getOAuthProvider;
use function Pi\AI\getOAuthProviders;
use function Pi\AI\registerOAuthProvider;
use function Pi\AI\resetOAuthProviders;
use function Pi\AI\unregisterOAuthProvider;
use function React\Promise\resolve;

describe('OAuth runtime helpers', function () {
    beforeEach(function () {
        resetOAuthProviders();
        OAuthHttp::setClientForTesting(null);
    });

    afterEach(function () {
        resetOAuthProviders();
        OAuthHttp::setClientForTesting(null);
    });

    it('registers and restores custom oauth providers', function () {
        $provider = new class implements OAuthProviderInterface
        {
            public function getId(): string
            {
                return 'test-oauth';
            }

            public function getName(): string
            {
                return 'Test OAuth';
            }

            public function usesCallbackServer(): bool
            {
                return false;
            }

            public function login(OAuthLoginCallbacks $callbacks): PromiseInterface
            {
                return resolve(new OAuthCredentials('refresh', 'access', time() * 1000 + 3600_000));
            }

            public function refreshToken(OAuthCredentials $credentials): PromiseInterface
            {
                return resolve($credentials);
            }

            public function getApiKey(OAuthCredentials $credentials): string
            {
                return $credentials->access;
            }

            public function modifyModels(array $models, OAuthCredentials $credentials): array
            {
                return $models;
            }
        };

        registerOAuthProvider($provider);

        expect(getOAuthProvider('test-oauth')?->getName())->toBe('Test OAuth');
        expect(array_map(static fn (OAuthProviderInterface $provider): string => $provider->getId(), getOAuthProviders()))->toContain('test-oauth');

        unregisterOAuthProvider('test-oauth');

        expect(getOAuthProvider('test-oauth'))->toBeNull();
    });

    it('refreshes openai codex oauth credentials and returns updated api key', function () {
        OAuthHttp::setClientForTesting(static function (string $method, string $url, array $headers, ?string $body): array {
            expect($method)->toBe('POST');
            expect($url)->toBe('https://auth.openai.com/oauth/token');

            $token = createTestJwt(['https://api.openai.com/auth' => ['chatgpt_account_id' => 'acct_123']]);

            return [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => $token,
                    'refresh_token' => 'refresh-new',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
            ];
        });

        $result = block(getOAuthApiKey('openai-codex', [
            'openai-codex' => new OAuthCredentials('refresh-old', 'access-old', 0, ['accountId' => 'acct_old']),
        ]));

        expect($result)->not->toBeNull();
        expect($result['apiKey'])->toBeString();
        expect($result['newCredentials'])->toBeInstanceOf(OAuthCredentials::class);
        expect($result['newCredentials']->refresh)->toBe('refresh-new');
        expect($result['newCredentials']->get('accountId'))->toBe('acct_123');
    });

    it('applies github copilot oauth model mutations', function () {
        $provider = getOAuthProvider('github-copilot');
        $models = [
            getModel('github-copilot', 'gpt-5.2-codex'),
            getModel('openai', 'gpt-5.4-mini'),
        ];
        $models = array_values(array_filter($models, static fn (?Model $model): bool => $model instanceof Model));

        $modified = $provider?->modifyModels(
            $models,
            new OAuthCredentials('refresh', 'tid=1;proxy-ep=proxy.enterprise.githubcopilot.com;exp=999', time() * 1000 + 3600_000),
        );

        expect($modified)->not->toBeNull();
        expect($modified[0]->baseUrl)->toBe('https://api.enterprise.githubcopilot.com');
        expect($modified[1]->baseUrl)->toBe($models[1]->baseUrl);
    });
});

function createTestJwt(array $payload): string
{
    $header = ['alg' => 'none', 'typ' => 'JWT'];
    $encode = static function (array $data): string {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    };

    return $encode($header).'.'.$encode($payload).'.signature';
}
