<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use React\Promise\PromiseInterface;

final class AnthropicOAuthProvider extends AbstractOAuthProvider
{
    private const CLIENT_ID = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';

    private const TOKEN_URL = 'https://platform.claude.com/v1/oauth/token';

    public function getId(): string
    {
        return 'anthropic';
    }

    public function getName(): string
    {
        return 'Anthropic (Claude Pro/Max)';
    }

    public function usesCallbackServer(): bool
    {
        return true;
    }

    public function refreshToken(OAuthCredentials $credentials): PromiseInterface
    {
        return OAuthHttp::request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode([
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $credentials->refresh,
        ], JSON_THROW_ON_ERROR))->then(function (array $response): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('Anthropic token refresh failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_string($json['refresh_token'] ?? null) || ! is_int($json['expires_in'] ?? null)) {
                throw new \RuntimeException('Anthropic token refresh returned invalid fields.');
            }

            return new OAuthCredentials(
                refresh: $json['refresh_token'],
                access: $json['access_token'],
                expires: (time() * 1000) + ($json['expires_in'] * 1000) - (5 * 60 * 1000),
            );
        });
    }

    public function getApiKey(OAuthCredentials $credentials): string
    {
        return $credentials->access;
    }
}
