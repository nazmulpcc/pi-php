<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use React\Promise\PromiseInterface;

final class OpenAICodexOAuthProvider extends AbstractOAuthProvider
{
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';

    public function getId(): string
    {
        return 'openai-codex';
    }

    public function getName(): string
    {
        return 'ChatGPT Plus/Pro (Codex Subscription)';
    }

    public function usesCallbackServer(): bool
    {
        return true;
    }

    public function refreshToken(OAuthCredentials $credentials): PromiseInterface
    {
        return OAuthHttp::request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials->refresh,
            'client_id' => self::CLIENT_ID,
        ], '', '&', PHP_QUERY_RFC3986))->then(function (array $response): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('OpenAI Codex token refresh failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_string($json['refresh_token'] ?? null) || ! is_int($json['expires_in'] ?? null)) {
                throw new \RuntimeException('OpenAI Codex token refresh returned invalid fields.');
            }

            $accountId = $this->extractAccountId($json['access_token']);
            if ($accountId === null) {
                throw new \RuntimeException('Failed to extract accountId from OpenAI Codex access token.');
            }

            return new OAuthCredentials(
                refresh: $json['refresh_token'],
                access: $json['access_token'],
                expires: (time() * 1000) + ($json['expires_in'] * 1000),
                extra: ['accountId' => $accountId],
            );
        });
    }

    public function getApiKey(OAuthCredentials $credentials): string
    {
        return $credentials->access;
    }

    private function extractAccountId(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $parts[1];
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if (! is_string($decoded)) {
            return null;
        }

        $json = json_decode($decoded, true);
        $accountId = $json['https://api.openai.com/auth']['chatgpt_account_id'] ?? null;

        return is_string($accountId) && $accountId !== '' ? $accountId : null;
    }
}
