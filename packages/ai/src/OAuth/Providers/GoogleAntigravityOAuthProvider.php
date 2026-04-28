<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\OAuth\CallbackServer;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use Pi\AI\OAuth\Pkce;
use Pi\AI\Support\PromiseHelper;
use React\Promise\PromiseInterface;

use function React\Promise\race;

final class GoogleAntigravityOAuthProvider extends AbstractOAuthProvider
{
    private const CLIENT_ID = '1071006060591-tmhssin2h21lcre235vtolojh4g403ep.apps.googleusercontent.com';

    private const CLIENT_SECRET = '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f';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REDIRECT_URI = 'http://localhost:51121/oauth-callback';

    private const CALLBACK_PORT = 51121;

    private const CALLBACK_PATH = '/oauth-callback';

    private const DEFAULT_PROJECT_ID = 'rising-fact-p41fc';

    private const SCOPES = [
        'https://www.googleapis.com/auth/cloud-platform',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/cclog',
        'https://www.googleapis.com/auth/experimentsandconfigs',
    ];

    public function getId(): string
    {
        return 'google-antigravity';
    }

    public function getName(): string
    {
        return 'Google Antigravity';
    }

    public function usesCallbackServer(): bool
    {
        return true;
    }

    public function login(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        $pkce = Pkce::generate();
        $state = $pkce['verifier'];
        $server = CallbackServer::start(
            host: getenv('PI_OAUTH_CALLBACK_HOST') ?: '127.0.0.1',
            port: self::CALLBACK_PORT,
            path: self::CALLBACK_PATH,
            expectedState: $state,
            successMessage: 'Google authentication completed. You can close this window.',
        );

        $authUrl = self::AUTH_URL.'?'.http_build_query([
            'client_id' => self::CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => implode(' ', self::SCOPES),
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);

        $this->progress($callbacks, 'Starting local server for OAuth callback...');
        $this->onAuth($callbacks, $authUrl, 'Complete the sign-in in your browser.');
        $this->progress($callbacks, 'Waiting for OAuth callback...');

        $callbackPromise = $server->waitForCode()->then(static fn (?array $result): string => $result['code'] ?? '');
        $manualPromise = $callbacks->onManualCodeInput !== null
            ? $this->manualCodeInput($callbacks)
            : PromiseHelper::resolve('');

        return race([$callbackPromise, $manualPromise])
            ->then(function (string $input) use ($callbacks, $pkce, $state): PromiseInterface {
                $parsed = $this->parseAuthorizationInput($input);
                if (($parsed['state'] ?? null) !== null && $parsed['state'] !== $state) {
                    throw new \RuntimeException('OAuth state mismatch');
                }

                $code = (string) ($parsed['code'] ?? '');
                if ($code === '') {
                    return $this->prompt($callbacks, 'Paste the authorization code or full redirect URL:', self::REDIRECT_URI)
                        ->then(fn (string $manual): PromiseInterface => $this->exchangeAuthorizationCode(
                            (string) ($this->parseAuthorizationInput($manual)['code'] ?? ''),
                            $pkce['verifier'],
                        ));
                }

                return $this->exchangeAuthorizationCode($code, $pkce['verifier']);
            })
            ->then(function (OAuthCredentials $credentials): OAuthCredentials {
                $projectId = getenv('GOOGLE_CLOUD_PROJECT') ?: getenv('GOOGLE_CLOUD_PROJECT_ID') ?: self::DEFAULT_PROJECT_ID;

                return new OAuthCredentials(
                    refresh: $credentials->refresh,
                    access: $credentials->access,
                    expires: $credentials->expires,
                    extra: ['projectId' => trim((string) $projectId)],
                );
            })
            ->then(
                function (OAuthCredentials $credentials) use ($server): OAuthCredentials {
                    $server->close();

                    return $credentials;
                },
                function (mixed $error) use ($server) {
                    $server->close();
                    throw $error instanceof \Throwable ? $error : new \RuntimeException((string) $error);
                },
            );
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

    /**
     * @return array{code?:string, state?:string}
     */
    private function parseAuthorizationInput(string $input): array
    {
        $value = trim($input);
        if ($value === '') {
            return [];
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $query = parse_url($value, PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $params);

            return [
                'code' => is_string($params['code'] ?? null) ? $params['code'] : null,
                'state' => is_string($params['state'] ?? null) ? $params['state'] : null,
            ];
        }

        parse_str($value, $params);

        return [
            'code' => is_string($params['code'] ?? null) ? $params['code'] : $value,
            'state' => is_string($params['state'] ?? null) ? $params['state'] : null,
        ];
    }

    /**
     * @return PromiseInterface<OAuthCredentials>
     */
    private function exchangeAuthorizationCode(string $code, string $verifier): PromiseInterface
    {
        if ($code === '') {
            throw new \RuntimeException('Missing authorization code');
        }

        return OAuthHttp::request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
        ], '', '&', PHP_QUERY_RFC3986))->then(function (array $response): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('Google Antigravity token exchange failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_string($json['refresh_token'] ?? null) || ! is_int($json['expires_in'] ?? null)) {
                throw new \RuntimeException('Google Antigravity token exchange returned invalid fields.');
            }

            return new OAuthCredentials(
                refresh: $json['refresh_token'],
                access: $json['access_token'],
                expires: (time() * 1000) + ($json['expires_in'] * 1000) - (5 * 60 * 1000),
            );
        });
    }
}
