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

final class OpenAICodexOAuthProvider extends AbstractOAuthProvider
{
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    private const AUTHORIZE_URL = 'https://auth.openai.com/oauth/authorize';

    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';

    private const REDIRECT_URI = 'http://localhost:1455/auth/callback';

    private const CALLBACK_PATH = '/auth/callback';

    private const SCOPE = 'openid profile email offline_access';

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

    public function login(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        return PromiseHelper::resolve($this->performLogin($callbacks));
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

    private function performLogin(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        $pkce = Pkce::generate();
        $state = Pkce::createState();
        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::SCOPE,
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'state' => $state,
            'id_token_add_organizations' => 'true',
            'codex_cli_simplified_flow' => 'true',
            'originator' => 'pi-php',
        ], '', '&', PHP_QUERY_RFC3986);

        $server = CallbackServer::start(
            host: getenv('PI_OAUTH_CALLBACK_HOST') ?: '127.0.0.1',
            port: 1455,
            path: self::CALLBACK_PATH,
            expectedState: $state,
            successMessage: 'OpenAI authentication completed. You can close this window.',
        );

        $this->onAuth($callbacks, $url, 'A browser window should open. Complete login to finish.');
        $this->progress($callbacks, 'Waiting for OAuth callback...');

        $callbackPromise = $server->waitForCode()
            ->then(static fn (?array $result): string => $result['code'] ?? '');
        $manualPromise = $callbacks->onManualCodeInput !== null
            ? $this->manualCodeInput($callbacks)
            : PromiseHelper::resolve('');

        return race([$callbackPromise, $manualPromise])
            ->then(function (string $input) use ($state, $pkce, $callbacks): PromiseInterface {
                $parsed = $this->parseAuthorizationInput($input);
                if (($parsed['state'] ?? null) !== null && $parsed['state'] !== $state) {
                    throw new \RuntimeException('State mismatch');
                }

                $code = (string) ($parsed['code'] ?? '');
                if ($code !== '') {
                    return $this->exchangeAuthorizationCode($code, $pkce['verifier']);
                }

                return $this->prompt($callbacks, 'Paste the authorization code (or full redirect URL):')
                    ->then(fn (string $manual): PromiseInterface => $this->exchangeAuthorizationCode(
                        (string) ($this->parseAuthorizationInput($manual)['code'] ?? ''),
                        $pkce['verifier'],
                    ));
            })
            ->then(function (OAuthCredentials $credentials): OAuthCredentials {
                $accountId = $this->extractAccountId($credentials->access);
                if ($accountId === null) {
                    throw new \RuntimeException('Failed to extract accountId from token');
                }

                return new OAuthCredentials(
                    refresh: $credentials->refresh,
                    access: $credentials->access,
                    expires: $credentials->expires,
                    extra: ['accountId' => $accountId],
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

    /**
     * @return array{code?:string, state?:string}
     */
    private function parseAuthorizationInput(string $input): array
    {
        $value = trim($input);
        if ($value === '') {
            return [];
        }

        try {
            $url = new \URL($value);

            return [
                'code' => $url->searchParams->get('code') ?? null,
                'state' => $url->searchParams->get('state') ?? null,
            ];
        } catch (\Throwable) {
        }

        if (str_contains($value, '#')) {
            [$code, $state] = array_pad(explode('#', $value, 2), 2, null);

            return ['code' => $code, 'state' => $state];
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
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => self::REDIRECT_URI,
        ], '', '&', PHP_QUERY_RFC3986))->then(function (array $response): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('OpenAI Codex token exchange failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_string($json['refresh_token'] ?? null) || ! is_int($json['expires_in'] ?? null)) {
                throw new \RuntimeException('OpenAI Codex token exchange returned invalid fields.');
            }

            return new OAuthCredentials(
                refresh: $json['refresh_token'],
                access: $json['access_token'],
                expires: (time() * 1000) + ($json['expires_in'] * 1000),
            );
        });
    }

    private function extractAccountId(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $json = Pkce::decodeBase64UrlJsonSegment($parts[1]);
        $accountId = $json['https://api.openai.com/auth']['chatgpt_account_id'] ?? null;

        return is_string($accountId) && $accountId !== '' ? $accountId : null;
    }
}
