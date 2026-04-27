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
use Pi\AI\Transport\SseParser;

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
            transport: function (Model $m, Context $c, $opts, array $params) use ($url, $headers, $providerOptions): array {
                $events = [];
                $status = 0;
                $responseHeaders = [];

                $curl = curl_init($url);
                if ($curl === false) {
                    throw new \RuntimeException('Unable to initialize cURL for Azure OpenAI Responses transport.');
                }

                $requestHeaders = ['Content-Type: application/json'];
                foreach ($headers as $name => $value) {
                    $requestHeaders[] = sprintf('%s: %s', $name, $value);
                }

                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => $requestHeaders,
                    CURLOPT_POSTFIELDS => json_encode($params, JSON_THROW_ON_ERROR),
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_HEADER => false,
                    CURLOPT_TIMEOUT_MS => $providerOptions->timeoutMs ?? 0,
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$events): int {
                        static $buffer = '';
                        $buffer .= $chunk;
                        while (($separator = strpos($buffer, "\n\n")) !== false) {
                            $frame = substr($buffer, 0, $separator);
                            $buffer = substr($buffer, $separator + 2);
                            $event = SseParser::parseFrame($frame);
                            if ($event !== null) {
                                $events[] = $event;
                            }
                        }

                        return strlen($chunk);
                    },
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$responseHeaders): int {
                        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
                            $status = (int) $matches[1];
                        } elseif (str_contains($line, ':')) {
                            [$name, $value] = explode(':', $line, 2);
                            $responseHeaders[strtolower(trim($name))] = trim($value);
                        }

                        return strlen($line);
                    },
                ]);

                $success = curl_exec($curl);
                $error = curl_error($curl);
                curl_close($curl);

                if ($success === false && $error !== '') {
                    throw new \RuntimeException($error !== '' ? $error : 'Unknown cURL error while calling Azure OpenAI Responses API.');
                }

                return [
                    'events' => $events,
                    'status' => $status,
                    'headers' => $responseHeaders,
                ];
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
