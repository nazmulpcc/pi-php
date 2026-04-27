<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use React\Promise\PromiseInterface;

final class GoogleAntigravityOAuthProvider extends AbstractOAuthProvider
{
    private const CLIENT_ID = '1071006060591-tmhssin2h21lcre235vtolojh4g403ep.apps.googleusercontent.com';

    private const CLIENT_SECRET = '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f';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function getId(): string
    {
        return 'google-antigravity';
    }

    public function getName(): string
    {
        return 'Google Antigravity';
    }

    public function refreshToken(OAuthCredentials $credentials): PromiseInterface
    {
        $projectId = $credentials->get('projectId');
        if (! is_string($projectId) || $projectId === '') {
            throw new \RuntimeException('Google Antigravity OAuth credentials are missing projectId.');
        }

        return OAuthHttp::request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'refresh_token' => $credentials->refresh,
            'grant_type' => 'refresh_token',
        ], '', '&', PHP_QUERY_RFC3986))->then(function (array $response) use ($projectId, $credentials): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('Google Antigravity token refresh failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_int($json['expires_in'] ?? null)) {
                throw new \RuntimeException('Google Antigravity token refresh returned invalid fields.');
            }

            return new OAuthCredentials(
                refresh: is_string($json['refresh_token'] ?? null) ? $json['refresh_token'] : $credentials->refresh,
                access: $json['access_token'],
                expires: (time() * 1000) + ($json['expires_in'] * 1000) - (5 * 60 * 1000),
                extra: ['projectId' => $projectId],
            );
        });
    }

    public function getApiKey(OAuthCredentials $credentials): string
    {
        return $credentials->access;
    }
}
