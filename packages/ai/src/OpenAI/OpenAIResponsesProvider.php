<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI;

use Closure;
use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\CacheRetention;
use Pi\AI\Context;
use Pi\AI\EnvApiKeys;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Model;
use Pi\AI\OpenAI\OpenAIResponsesOptions as ProviderOptions;
use Pi\AI\OpenAI\SimpleOptions as ProviderSimpleOptions;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Support\PromiseHelper;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Usage;
use React\Promise\PromiseInterface;

final readonly class OpenAIResponsesProvider implements ApiProviderInterface
{
    /**
     * @param  null|callable(Model, Context, ProviderOptions, array<string, mixed>): iterable<array<string, mixed>>  $transport
     */
    public function __construct(
        private ?Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::OPENAI_RESPONSES);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof ProviderOptions ? $options : new ProviderOptions(
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

        PromiseHelper::start(
            function () use ($model, $context, $providerOptions, $stream) {
                $params = $this->buildParams($model, $context, $providerOptions);

                return PromiseHelper::resolve($providerOptions->onPayload?->__invoke($params, $model))
                    ->then(function ($nextParams) use ($model, $context, $providerOptions, $params) {
                        if (is_array($nextParams)) {
                            $params = $nextParams;
                        }

                        if (! is_callable($this->transport)) {
                            return $this->runDefaultTransport($model, $context, $providerOptions, $params, $stream);
                        }

                        return PromiseHelper::resolve(($this->transport)($model, $context, $providerOptions, $params));
                    })
                    ->then(function ($result) use ($stream, $model) {
                        if ($result instanceof AssistantMessage) {
                            $stream->end();

                            return null;
                        }

                        $events = is_array($result) && array_key_exists('events', $result) ? $result['events'] : $result;
                        OpenAIResponsesShared::processStream($events, $stream, $model);
                        $stream->end();

                        return null;
                    });
            },
            function (\Throwable $error) use ($stream, $model): void {
                $message = new AssistantMessage(
                    content: [],
                    api: $model->api,
                    provider: $model->provider,
                    model: $model->id,
                    usage: Usage::zero(),
                    stopReason: StopReason::Error,
                    timestamp: time(),
                    errorMessage: $error->getMessage(),
                );
                $stream->push(new ErrorEvent(StopReason::Error, $message));
                $stream->end();
            },
        );

        return $stream;
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = ProviderSimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);
        $reasoning = $model->reasoning
            ? (($model->id !== '' && \Pi\AI\supportsXhigh($model)) ? $options?->reasoning : ProviderSimpleOptions::clampReasoning($options?->reasoning))
            : null;

        return $this->stream($model, $context, new ProviderOptions(
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

    /**
     * @return array<string, mixed>
     */
    private function buildParams(Model $model, Context $context, ProviderOptions $options): array
    {
        $params = [
            'model' => $model->id,
            'input' => OpenAIResponsesShared::convertMessages($model, $context, ['openai', 'openai-codex', 'opencode']),
            'stream' => true,
            'prompt_cache_key' => $options->cacheRetention === CacheRetention::None ? null : $options->sessionId,
            'prompt_cache_retention' => $options->cacheRetention === CacheRetention::Long ? '24h' : null,
            'store' => false,
        ];

        if ($options->maxTokens !== null) {
            $params['max_output_tokens'] = $options->maxTokens;
        }

        if ($options->temperature !== null) {
            $params['temperature'] = $options->temperature;
        }

        if ($options->serviceTier !== null) {
            $params['service_tier'] = $options->serviceTier;
        }

        if ($context->tools !== []) {
            $params['tools'] = OpenAIResponsesShared::convertTools($context->tools);
        }

        if ($model->reasoning && $options->reasoningEffort !== null) {
            $params['reasoning'] = [
                'effort' => $options->reasoningEffort,
                'summary' => $options->reasoningSummary ?? 'auto',
            ];
            $params['include'] = ['reasoning.encrypted_content'];
        }

        return $params;
    }

    /**
     * @return PromiseInterface<AssistantMessage>
     */
    private function runDefaultTransport(Model $model, Context $context, ProviderOptions $options, array $params, AssistantMessageEventStream $stream): PromiseInterface
    {
        $apiKey = $options->apiKey ?: EnvApiKeys::getEnvApiKey('openai') ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('OpenAI API key is required. Set OPENAI_API_KEY or pass apiKey explicitly.');
        }

        $url = rtrim($model->baseUrl, '/').'/responses';
        $headers = array_merge($model->headers, $options->headers);

        $transport = new HttpTransport(
            signal: $options->signal,
            timeoutMs: $options->timeoutMs,
            maxRetries: $options->maxRetries,
            maxRetryDelayMs: $options->maxRetryDelayMs,
        );

        $state = OpenAIResponsesShared::initializeStreamState($stream, $model);

        return $transport->stream('POST', $url, [
            'headers' => $headers,
            'body' => $params,
            'apiKey' => $apiKey,
            'onResponse' => $options->onResponse !== null
                ? static function (array $response) use ($options, $model): mixed {
                    return $options->onResponse?->__invoke([
                        'status' => $response['status'],
                        'headers' => $response['headers'],
                    ], $model);
                }
                : null,
            'onEvent' => function (array $event) use (&$state, $stream, $model): void {
                OpenAIResponsesShared::processStreamEvent($event, $stream, $model, $state);
            },
        ])->then(function () use (&$state, $stream, $model): AssistantMessage {
            $output = OpenAIResponsesShared::finalizeStreamState($stream, $model, $state);
            $stream->push(new \Pi\AI\Event\DoneEvent($output->stopReason, $output));

            return $output;
        });
    }
}
