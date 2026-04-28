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

final class AnthropicOAuthProvider extends AbstractOAuthProvider
{
    private const CLIENT_ID = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';

    private const AUTHORIZE_URL = 'https://claude.ai/oauth/authorize';

    private const TOKEN_URL = 'https://platform.claude.com/v1/oauth/token';

    private const CALLBACK_PORT = 53692;

    private const CALLBACK_PATH = '/callback';

    private const REDIRECT_URI = 'http://localhost:53692/callback';

    private const SCOPES = 'org:create_api_key user:profile user:inference user:sessions:claude_code user:mcp_servers user:file_upload';

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

    public function login(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        return PromiseHelper::resolve($this->performLogin($callbacks));
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

    private function performLogin(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        $pkce = Pkce::generate();
        $state = $pkce['verifier'];
        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'code' => 'true',
            'client_id' => self::CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::SCOPES,
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        $server = CallbackServer::start(
            host: getenv('PI_OAUTH_CALLBACK_HOST') ?: '127.0.0.1',
            port: self::CALLBACK_PORT,
            path: self::CALLBACK_PATH,
            expectedState: $state,
            successMessage: 'Anthropic authentication completed. You can close this window.',
        );

        $this->onAuth($callbacks, $url, 'Complete login in your browser. If the browser is on another machine, paste the final redirect URL here.');

        $callbackPromise = $server->waitForCode()
            ->then(static fn (?array $result): string => $result['code'] ?? '');
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
                if ($code !== '') {
                    return $this->exchangeAuthorizationCode($code, $state, $pkce['verifier']);
                }

                return $this->prompt($callbacks, 'Paste the authorization code or full redirect URL:', self::REDIRECT_URI)
                    ->then(fn (string $manual): PromiseInterface => $this->exchangeAuthorizationCode(
                        (string) ($this->parseAuthorizationInput($manual)['code'] ?? ''),
                        $state,
                        $pkce['verifier'],
                    ));
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

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $query = parse_url($value, PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $params);

            return [
                'code' => is_string($params['code'] ?? null) ? $params['code'] : null,
                'state' => is_string($params['state'] ?? null) ? $params['state'] : null,
            ];
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
    private function exchangeAuthorizationCode(string $code, string $state, string $verifier): PromiseInterface
    {
        if ($code === '') {
            throw new \RuntimeException('Missing authorization code');
        }

        $thisState = $state;

        return OAuthHttp::request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'state' => $thisState,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
        ], JSON_THROW_ON_ERROR))->then(function (array $response): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('Anthropic token exchange failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_string($json['refresh_token'] ?? null) || ! is_int($json['expires_in'] ?? null)) {
                throw new \RuntimeException('Anthropic token exchange returned invalid fields.');
            }

            return new OAuthCredentials(
                refresh: $json['refresh_token'],
                access: $json['access_token'],
                expires: (time() * 1000) + ($json['expires_in'] * 1000) - (5 * 60 * 1000),
            );
        });
    }
}
