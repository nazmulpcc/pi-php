<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\Model;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthHttp;
use React\Promise\PromiseInterface;

final class GitHubCopilotOAuthProvider extends AbstractOAuthProvider
{
    private const DEFAULT_DOMAIN = 'github.com';

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
}
