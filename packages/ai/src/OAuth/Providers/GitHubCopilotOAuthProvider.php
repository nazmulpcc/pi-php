<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\Model;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class GitHubCopilotOAuthProvider extends AbstractOAuthProvider
{
    private const DEFAULT_DOMAIN = 'github.com';

    private const CLIENT_ID = 'SXYxLmI1MDdhMDhjODdlY2ZlOTg=';

    /** @var array<string, string> */
    private const HEADERS = [
        'Accept' => 'application/json',
        'User-Agent' => 'GitHubCopilotChat/0.35.0',
        'Editor-Version' => 'vscode/1.107.0',
        'Editor-Plugin-Version' => 'copilot-chat/0.35.0',
        'Copilot-Integration-Id' => 'vscode-chat',
    ];

    public function getId(): string
    {
        return 'github-copilot';
    }

    public function getName(): string
    {
        return 'GitHub Copilot';
    }

    public function login(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        return $this->prompt($callbacks, 'GitHub Enterprise URL/domain (blank for github.com)', 'company.ghe.com', true)
            ->then(function (string $input) use ($callbacks): PromiseInterface {
                $trimmed = trim($input);
                $enterpriseDomain = $trimmed !== '' ? $this->normalizeDomain($trimmed) : null;
                if ($trimmed !== '' && $enterpriseDomain === null) {
                    throw new \RuntimeException('Invalid GitHub Enterprise URL/domain');
                }

                $domain = $enterpriseDomain ?? self::DEFAULT_DOMAIN;

                return $this->startDeviceFlow($domain)
                    ->then(function (array $device) use ($callbacks, $domain, $enterpriseDomain): PromiseInterface {
                        $this->onAuth(
                            $callbacks,
                            (string) $device['verification_uri'],
                            sprintf('Enter code: %s', $device['user_code']),
                        );

                        return $this->pollForGitHubAccessToken(
                            $domain,
                            (string) $device['device_code'],
                            (int) $device['interval'],
                            (int) $device['expires_in'],
                        )->then(fn (string $githubAccessToken): PromiseInterface => $this->refreshGitHubAccessToken(
                            $githubAccessToken,
                            $enterpriseDomain,
                        ));
                    });
            });
    }

    public function refreshToken(OAuthCredentials $credentials): PromiseInterface
    {
        $enterpriseUrl = is_string($credentials->get('enterpriseUrl')) ? $credentials->get('enterpriseUrl') : null;
        $domain = $enterpriseUrl ?: self::DEFAULT_DOMAIN;
        $url = sprintf('https://api.%s/copilot_internal/v2/token', $domain);

        return OAuthHttp::request('GET', $url, [
            ...self::HEADERS,
            'Authorization' => 'Bearer '.$credentials->refresh,
        ])->then(function (array $response) use ($enterpriseUrl): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('GitHub Copilot token refresh failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['token'] ?? null) || ! is_int($json['expires_at'] ?? null)) {
                throw new \RuntimeException('GitHub Copilot token refresh returned invalid fields.');
            }

            $extra = [];
            if ($enterpriseUrl !== null) {
                $extra['enterpriseUrl'] = $enterpriseUrl;
            }

            return new OAuthCredentials(
                refresh: $credentials->refresh,
                access: $json['token'],
                expires: ($json['expires_at'] * 1000) - (5 * 60 * 1000),
                extra: $extra,
            );
        });
    }

    public function getApiKey(OAuthCredentials $credentials): string
    {
        return $credentials->access;
    }

    /**
     * @param  array<Model>  $models
     * @return array<Model>
     */
    public function modifyModels(array $models, OAuthCredentials $credentials): array
    {
        $domain = is_string($credentials->get('enterpriseUrl')) && $credentials->get('enterpriseUrl') !== ''
            ? $credentials->get('enterpriseUrl')
            : null;
        $baseUrl = $this->getBaseUrl($credentials->access, $domain);

        return array_map(static function (Model $model) use ($baseUrl): Model {
            if (! $model->provider->equals('github-copilot')) {
                return $model;
            }

            return new Model(
                id: $model->id,
                name: $model->name,
                api: $model->api,
                provider: $model->provider,
                baseUrl: $baseUrl,
                reasoning: $model->reasoning,
                input: $model->input,
                cost: $model->cost,
                contextWindow: $model->contextWindow,
                maxTokens: $model->maxTokens,
                headers: $model->headers,
                compat: $model->compat,
            );
        }, $models);
    }

    private function getBaseUrl(string $token, ?string $enterpriseDomain): string
    {
        if (preg_match('/proxy-ep=([^;]+)/', $token, $matches) === 1 && isset($matches[1])) {
            return 'https://'.preg_replace('/^proxy\./', 'api.', $matches[1]);
        }

        if ($enterpriseDomain !== null && $enterpriseDomain !== '') {
            return sprintf('https://copilot-api.%s', $enterpriseDomain);
        }

        return 'https://api.individual.githubcopilot.com';
    }

    private function normalizeDomain(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        try {
            $candidate = str_contains($trimmed, '://') ? $trimmed : 'https://'.$trimmed;
            $host = parse_url($candidate, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return PromiseInterface<array{device_code:string,user_code:string,verification_uri:string,interval:int,expires_in:int}>
     */
    private function startDeviceFlow(string $domain): PromiseInterface
    {
        return OAuthHttp::request('POST', sprintf('https://%s/login/device/code', $domain), [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'GitHubCopilotChat/0.35.0',
        ], http_build_query([
            'client_id' => base64_decode(self::CLIENT_ID),
            'scope' => 'read:user',
        ], '', '&', PHP_QUERY_RFC3986))->then(function (array $response): array {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('GitHub device flow start failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (
                ! is_array($json)
                || ! is_string($json['device_code'] ?? null)
                || ! is_string($json['user_code'] ?? null)
                || ! is_string($json['verification_uri'] ?? null)
                || ! is_int($json['interval'] ?? null)
                || ! is_int($json['expires_in'] ?? null)
            ) {
                throw new \RuntimeException('Invalid GitHub device code response');
            }

            return $json;
        });
    }

    /**
     * @return PromiseInterface<string>
     */
    private function pollForGitHubAccessToken(string $domain, string $deviceCode, int $intervalSeconds, int $expiresIn): PromiseInterface
    {
        $deadline = time() + $expiresIn;
        $intervalMs = max(50, $intervalSeconds * 1000);

        return $this->pollDeviceToken($domain, $deviceCode, $deadline, $intervalMs);
    }

    /**
     * @return PromiseInterface<string>
     */
    private function pollDeviceToken(string $domain, string $deviceCode, int $deadline, int $intervalMs): PromiseInterface
    {
        if (time() >= $deadline) {
            throw new \RuntimeException('Device flow timed out');
        }

        return $this->sleep($intervalMs)
            ->then(fn (): PromiseInterface => OAuthHttp::request('POST', sprintf('https://%s/login/oauth/access_token', $domain), [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'GitHubCopilotChat/0.35.0',
            ], http_build_query([
                'client_id' => base64_decode(self::CLIENT_ID),
                'device_code' => $deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ], '', '&', PHP_QUERY_RFC3986)))
            ->then(function (array $response) use ($domain, $deviceCode, $deadline, $intervalMs): PromiseInterface|string {
                if ($response['status'] < 200 || $response['status'] >= 300) {
                    throw new \RuntimeException(sprintf('GitHub device flow polling failed: %s', $response['body']));
                }

                $json = json_decode($response['body'], true);
                if (is_array($json) && is_string($json['access_token'] ?? null) && $json['access_token'] !== '') {
                    return $json['access_token'];
                }

                $error = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : null;
                if ($error === 'authorization_pending') {
                    return $this->pollDeviceToken($domain, $deviceCode, $deadline, $intervalMs);
                }

                if ($error === 'slow_down') {
                    $nextInterval = is_int($json['interval'] ?? null)
                        ? ((int) $json['interval']) * 1000
                        : ($intervalMs + 5000);

                    return $this->pollDeviceToken($domain, $deviceCode, $deadline, $nextInterval);
                }

                if ($error !== null) {
                    throw new \RuntimeException(sprintf('Device flow failed: %s', $error));
                }

                throw new \RuntimeException('Invalid GitHub device token response');
            })
            ->then(static fn (mixed $token): string => is_string($token) ? $token : '');
    }

    /**
     * @return PromiseInterface<OAuthCredentials>
     */
    private function refreshGitHubAccessToken(string $refreshToken, ?string $enterpriseDomain): PromiseInterface
    {
        $domain = $enterpriseDomain ?: self::DEFAULT_DOMAIN;
        $url = sprintf('https://api.%s/copilot_internal/v2/token', $domain);

        return OAuthHttp::request('GET', $url, [
            ...self::HEADERS,
            'Authorization' => 'Bearer '.$refreshToken,
        ])->then(function (array $response) use ($enterpriseDomain, $refreshToken): OAuthCredentials {
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException(sprintf('GitHub Copilot token refresh failed: %s', $response['body']));
            }

            $json = json_decode($response['body'], true);
            if (! is_array($json) || ! is_string($json['token'] ?? null) || ! is_int($json['expires_at'] ?? null)) {
                throw new \RuntimeException('GitHub Copilot token refresh returned invalid fields.');
            }

            $extra = [];
            if ($enterpriseDomain !== null && $enterpriseDomain !== '') {
                $extra['enterpriseUrl'] = $enterpriseDomain;
            }

            return new OAuthCredentials(
                refresh: $refreshToken,
                access: $json['token'],
                expires: ($json['expires_at'] * 1000) - (5 * 60 * 1000),
                extra: $extra,
            );
        });
    }

    /**
     * @return PromiseInterface<void>
     */
    private function sleep(int $milliseconds): PromiseInterface
    {
        $deferred = new Deferred;
        Loop::addTimer($milliseconds / 1000, static function () use ($deferred): void {
            $deferred->resolve(null);
        });

        return $deferred->promise();
    }
}
