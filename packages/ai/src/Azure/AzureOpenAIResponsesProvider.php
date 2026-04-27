<?php

declare(strict_types=1);

namespace Pi\AI\Azure;

use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\CacheRetention;
use Pi\AI\Context;
use Pi\AI\EnvApiKeys;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\OpenAI\OpenAIResponsesOptions;
use Pi\AI\OpenAI\OpenAIResponsesProvider;
use Pi\AI\OpenAI\SimpleOptions;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StreamOptions;
use Pi\AI\Transport\HttpTransport;

final readonly class AzureOpenAIResponsesProvider implements ApiProviderInterface
{
    public function getApi(): Api
    {
        return new Api(Api::AZURE_OPENAI_RESPONSES);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $providerOptions = $options instanceof AzureOpenAIResponsesOptions
            ? $options
            : self::mapToProviderOptions($options);

        $apiKey = $providerOptions->apiKey ?: EnvApiKeys::getEnvApiKey('azure-openai-responses') ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('Azure OpenAI API key is required. Set AZURE_OPENAI_API_KEY or pass apiKey explicitly.');
        }

        $baseUrl = $model->baseUrl;
        if ($baseUrl === '') {
            $resourceName = getenv('AZURE_OPENAI_RESOURCE_NAME') ?: null;
            if ($resourceName !== null && $resourceName !== '') {
                $baseUrl = sprintf('https://%s.openai.azure.com/openai', $resourceName);
            } else {
                throw new \RuntimeException('Azure OpenAI base URL is required. Set AZURE_OPENAI_BASE_URL or AZURE_OPENAI_RESOURCE_NAME, or pass a model with baseUrl.');
            }
        }

        $apiVersion = getenv('AZURE_OPENAI_API_VERSION') ?: 'v1';
        $url = rtrim($baseUrl, '/').'/responses?api-version='.$apiVersion;

        $headers = array_merge($model->headers, $providerOptions->headers);
        $headers['api-key'] = $apiKey;

        $innerProvider = new OpenAIResponsesProvider(
            transport: function (Model $m, Context $c, $opts, array $params) use ($url, $headers, $providerOptions) {
                $transport = new HttpTransport(
                    signal: $providerOptions->signal,
                    timeoutMs: $providerOptions->timeoutMs,
                    maxRetries: $providerOptions->maxRetries,
                    maxRetryDelayMs: $providerOptions->maxRetryDelayMs,
                );

                return $transport->stream('POST', $url, [
                    'headers' => $headers,
                    'body' => $params,
                    'onResponse' => $providerOptions->onResponse !== null
                        ? static function (array $response) use ($providerOptions, $m): mixed {
                            return $providerOptions->onResponse?->__invoke([
                                'status' => $response['status'],
                                'headers' => $response['headers'],
                            ], $m);
                        }
                        : null,
                ]);
            },
        );

        $stream = $innerProvider->stream($model, $context, new OpenAIResponsesOptions(
            temperature: $providerOptions->temperature,
            maxTokens: $providerOptions->maxTokens,
            signal: $providerOptions->signal,
            apiKey: $apiKey,
            transport: $providerOptions->transport,
            cacheRetention: $providerOptions->cacheRetention,
            sessionId: $providerOptions->sessionId,
            onPayload: $providerOptions->onPayload,
            onResponse: $providerOptions->onResponse,
            headers: $providerOptions->headers,
            timeoutMs: $providerOptions->timeoutMs,
            maxRetries: $providerOptions->maxRetries,
            maxRetryDelayMs: $providerOptions->maxRetryDelayMs,
            metadata: $providerOptions->metadata,
            reasoningEffort: $providerOptions->reasoningEffort,
            reasoningSummary: $providerOptions->reasoningSummary,
            serviceTier: $providerOptions->serviceTier,
        ));

        return $stream;
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = SimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);
        $reasoning = Models::supportsXhigh($model) ? $options?->reasoning : SimpleOptions::clampReasoning($options?->reasoning);

        return $this->stream($model, $context, new AzureOpenAIResponsesOptions(
            temperature: $base->temperature,
            maxTokens: $base->maxTokens,
            signal: $base->signal,
            apiKey: $base->apiKey,
            transport: $base->transport,
            cacheRetention: $base->cacheRetention,
            sessionId: $base->sessionId,
            onPayload: $base->onPayload,
            onResponse: $base->onResponse,
            headers: $base->headers,
            timeoutMs: $base->timeoutMs,
            maxRetries: $base->maxRetries,
            maxRetryDelayMs: $base->maxRetryDelayMs,
            metadata: $base->metadata,
            reasoningEffort: $reasoning?->value,
        ));
    }

    private static function mapToProviderOptions(?StreamOptions $options): AzureOpenAIResponsesOptions
    {
        return new AzureOpenAIResponsesOptions(
            temperature: $options?->temperature,
            maxTokens: $options?->maxTokens,
            signal: $options?->signal,
            apiKey: $options?->apiKey,
            transport: $options?->transport,
            cacheRetention: $options?->cacheRetention ?? CacheRetention::Short,
            sessionId: $options?->sessionId,
            onPayload: $options?->onPayload,
            onResponse: $options?->onResponse,
            headers: $options?->headers ?? [],
            timeoutMs: $options?->timeoutMs,
            maxRetries: $options?->maxRetries,
            maxRetryDelayMs: $options?->maxRetryDelayMs,
            metadata: $options?->metadata ?? [],
        );
    }
}
