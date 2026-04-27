<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use React\Promise\PromiseInterface;

final class GoogleGeminiCliOAuthProvider extends AbstractOAuthProvider
{
    private const CLIENT_ID = 'NjgxMjU1ODA5Mzk1LW9vOGZ0Mm9wcmRybnA5ZTNhcWY2YXYzaG1kaWIxMzVqLmFwcHMuZ29vZ2xldXNlcmNvbnRlbnQuY29t';

    private const CLIENT_SECRET = 'R09DU1BYLTR1SGdNUG0tMW83U2stZ2VWNkN1NWNsWEZzeGw=';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function getId(): string
    {
        return 'google-gemini-cli';
    }

    public function getName(): string
    {
        return 'Google Gemini CLI';
    }

    public function refreshToken(OAuthCredentials $credentials): PromiseInterface
    {
        $projectId = $credentials->get('projectId');
        if (! is_string($projectId) || $projectId === '') {
            throw new \RuntimeException('Google Gemini CLI OAuth credentials are missing projectId.');
        }

        return OAuthHttp::request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => base64_decode(self::CLIENT_ID),
            'client_secret' => base64_decode(self::CLIENT_SECRET),
            'refresh_token' => $credentials->refresh,
            'grant_type' => 'refresh_token',
        ], '', '&', PHP_QUERY_RFC3986))->then(function (array $response) use ($projectId, $credentials): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('Google Gemini CLI token refresh failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_int($json['expires_in'] ?? null)) {
                throw new \RuntimeException('Google Gemini CLI token refresh returned invalid fields.');
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
