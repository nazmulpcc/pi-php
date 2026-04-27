<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI\Codex;

use Closure;
use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\CacheRetention;
use Pi\AI\Context;
use Pi\AI\EnvApiKeys;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\OpenAI\OpenAIResponsesShared;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Support\PromiseHelper;
use Pi\AI\ThinkingLevel;
use Pi\AI\Transport;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Usage;
use React\Promise\PromiseInterface;

final readonly class OpenAICodexResponsesProvider implements ApiProviderInterface
{
    /**
     * @param  null|callable(Model, Context, OpenAICodexResponsesOptions, array<string, mixed>): PromiseInterface<iterable<array<string, mixed>>|array{events: iterable<array<string, mixed>>, status?: int, headers?: array<string, string>}>|iterable<array<string, mixed>>|array{events: iterable<array<string, mixed>>, status?: int, headers?: array<string, string>}  $transport
     */
    public function __construct(
        private ?Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::OPENAI_CODEX_RESPONSES);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof OpenAICodexResponsesOptions ? $options : $this->mapToProviderOptions($options);

        PromiseHelper::start(
            function () use ($model, $context, $providerOptions, $stream) {
                $params = $this->buildParams($model, $context, $providerOptions);

                return PromiseHelper::resolve($providerOptions->onPayload?->__invoke($params, $model))
                    ->then(function ($nextParams) use ($model, $context, $providerOptions, $params, $stream) {
                        if (is_array($nextParams)) {
                            $params = $nextParams;
                        }

                        if (! is_callable($this->transport)) {
                            return $this->runDefaultTransport($model, $providerOptions, $params, $stream);
                        }

                        return PromiseHelper::resolve(($this->transport)($model, $context, $providerOptions, $params));
                    })
                    ->then(function ($result) use ($providerOptions, $stream, $model) {
                        if ($result instanceof AssistantMessage) {
                            $stream->end();

                            return null;
                        }

                        $events = is_array($result) && array_key_exists('events', $result) ? $result['events'] : $result;
                        OpenAIResponsesShared::processStream($events, $stream, $model);

                        if ($this->transport !== null && $providerOptions->onResponse !== null) {
                            return PromiseHelper::resolve($providerOptions->onResponse->__invoke([
                                'status' => is_array($result) && array_key_exists('status', $result) ? $result['status'] : 200,
                                'headers' => is_array($result) && array_key_exists('headers', $result) ? $result['headers'] : [],
                            ], $model));
                        }

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
                $stream->end($message);
            },
        );

        return $stream;
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $reasoning = $model->reasoning
            ? (($model->id !== '' && Models::supportsXhigh($model)) ? $options?->reasoning : self::clampReasoning($options?->reasoning))
            : null;

        return $this->stream($model, $context, new OpenAICodexResponsesOptions(
            temperature: $options?->temperature,
            maxTokens: $options?->maxTokens,
            signal: $options?->signal,
            apiKey: $options?->apiKey,
            transport: $options?->transport,
            cacheRetention: $options?->cacheRetention,
            sessionId: $options?->sessionId,
            onPayload: $options?->onPayload,
            onResponse: $options?->onResponse,
            headers: $options?->headers,
            timeoutMs: $options?->timeoutMs,
            maxRetries: $options?->maxRetries,
            maxRetryDelayMs: $options?->maxRetryDelayMs,
            metadata: $options?->metadata,
            reasoningEffort: $reasoning?->value,
            textVerbosity: 'low',
        ));
    }

    private function mapToProviderOptions(?StreamOptions $options): OpenAICodexResponsesOptions
    {
        return new OpenAICodexResponsesOptions(
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

    /**
     * @return array<string, mixed>
     */
    private function buildParams(Model $model, Context $context, OpenAICodexResponsesOptions $options): array
    {
        $params = [
            'model' => $model->id,
            'store' => false,
            'stream' => true,
            'instructions' => $context->systemPrompt,
            'input' => OpenAIResponsesShared::convertMessages($model, $context, ['openai', 'openai-codex', 'opencode'], [
                'includeSystemPrompt' => false,
            ]),
            'text' => ['verbosity' => $options->textVerbosity ?? 'low'],
            'include' => ['reasoning.encrypted_content'],
            'prompt_cache_key' => $options->sessionId,
            'tool_choice' => 'auto',
            'parallel_tool_calls' => true,
        ];

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
        }

        return $params;
    }

    /**
     * @return PromiseInterface<AssistantMessage|array{events: iterable<array<string, mixed>>, status: int, headers: array<string, string>}>
     */
    private function runDefaultTransport(Model $model, OpenAICodexResponsesOptions $options, array $params, AssistantMessageEventStream $stream): PromiseInterface
    {
        $apiKey = $options->apiKey ?: EnvApiKeys::getEnvApiKey('openai-codex') ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('OpenAI Codex API key is required. Pass apiKey explicitly.');
        }

        $accountId = self::extractAccountId($apiKey);
        $transportMode = $options->transport ?? Transport::Sse;
        $sseUrl = self::resolveCodexUrl($model->baseUrl);
        $webSocketUrl = self::resolveCodexWebSocketUrl($model->baseUrl);
        $sseHeaders = self::buildSseHeaders($model, $options, $accountId, $apiKey);
        $requestId = $options->sessionId ?: self::createCodexRequestId();
        $webSocketHeaders = self::buildWebSocketHeaders($model, $options, $accountId, $apiKey, $requestId);

        if ($transportMode !== Transport::Sse) {
            $started = false;
            $webSocketTransport = new CodexWebSocketTransport;
            $state = null;

            $attempt = $webSocketTransport->stream(
                $webSocketUrl,
                $webSocketHeaders,
                $params,
                $options->sessionId,
                $options->signal,
                function () use (&$started, &$state, $stream, $model): void {
                    $started = true;
                    $state = OpenAIResponsesShared::initializeStreamState($stream, $model);
                },
                function (array $event) use (&$state, $stream, $model): void {
                    if (! is_array($state)) {
                        throw new \RuntimeException('Codex websocket stream started without initialized state.');
                    }

                    OpenAIResponsesShared::processStreamEvent($event, $stream, $model, $state);
                },
            )->then(function () use (&$state, $stream, $model): AssistantMessage {
                if (! is_array($state)) {
                    throw new \RuntimeException('Codex websocket stream completed without initialized state.');
                }

                $output = OpenAIResponsesShared::finalizeStreamState($stream, $model, $state);
                $stream->push(new DoneEvent($output->stopReason, $output));

                return $output;
            });

            if ($transportMode === Transport::Websocket) {
                return $attempt;
            }

            return $attempt->then(
                null,
                function (\Throwable $error) use (&$started, $model, $options, $params, $stream, $sseUrl, $sseHeaders, $apiKey) {
                    if ($started) {
                        throw $error;
                    }

                    return $this->runSseTransport($model, $options, $params, $stream, $sseUrl, $sseHeaders, $apiKey);
                },
            );
        }

        return $this->runSseTransport($model, $options, $params, $stream, $sseUrl, $sseHeaders, $apiKey);
    }

    /**
     * @param  array<string, string>  $headers
     * @return PromiseInterface<AssistantMessage>
     */
    private function runSseTransport(
        Model $model,
        OpenAICodexResponsesOptions $options,
        array $params,
        AssistantMessageEventStream $stream,
        string $url,
        array $headers,
        string $apiKey,
    ): PromiseInterface {
        $responseStatus = 200;
        $responseHeaders = [];

        $onResponse = null;
        if ($options->onResponse !== null) {
            $onResponse = static function (array $response) use (&$responseStatus, &$responseHeaders): void {
                $responseStatus = $response['status'];
                $responseHeaders = $response['headers'];
            };
        }

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
            'onResponse' => $onResponse,
            'onEvent' => function (array $event) use (&$state, $stream, $model): void {
                OpenAIResponsesShared::processStreamEvent($event, $stream, $model, $state);
            },
        ])->then(function () use (&$state, $stream, $model, $options, $responseHeaders): AssistantMessage {
            $output = OpenAIResponsesShared::finalizeStreamState($stream, $model, $state);
            $stream->push(new DoneEvent($output->stopReason, $output));

            if ($options->onResponse !== null && $responseHeaders !== []) {
                // onResponse already ran inside transport; keep captured data only for parity/debugging.
            }

            return $output;
        });
    }

    /**
     * @return array<string, string>
     */
    private static function buildSseHeaders(Model $model, OpenAICodexResponsesOptions $options, string $accountId, string $token): array
    {
        $headers = array_merge($model->headers, $options->headers, [
            'Authorization' => 'Bearer '.$token,
            'chatgpt-account-id' => $accountId,
            'originator' => 'pi',
            'OpenAI-Beta' => 'responses=experimental',
            'Accept' => 'text/event-stream',
            'Content-Type' => 'application/json',
        ]);

        if ($options->sessionId !== null && $options->sessionId !== '') {
            $headers['session_id'] = $options->sessionId;
            $headers['x-client-request-id'] = $options->sessionId;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private static function buildWebSocketHeaders(Model $model, OpenAICodexResponsesOptions $options, string $accountId, string $token, string $requestId): array
    {
        return array_merge($model->headers, $options->headers, [
            'Authorization' => 'Bearer '.$token,
            'chatgpt-account-id' => $accountId,
            'originator' => 'pi',
            'OpenAI-Beta' => 'responses_websockets=2026-02-06',
            'x-client-request-id' => $requestId,
            'session_id' => $requestId,
        ]);
    }

    private static function resolveCodexWebSocketUrl(?string $baseUrl): string
    {
        $httpsUrl = self::resolveCodexUrl($baseUrl);

        if (str_starts_with($httpsUrl, 'https://')) {
            return 'wss://'.substr($httpsUrl, 8);
        }

        if (str_starts_with($httpsUrl, 'http://')) {
            return 'ws://'.substr($httpsUrl, 7);
        }

        return $httpsUrl;
    }

    private static function createCodexRequestId(): string
    {
        if (function_exists('random_bytes')) {
            return sprintf('codex_%s', bin2hex(random_bytes(8)));
        }

        return sprintf('codex_%s', uniqid('', true));
    }

    private static function resolveCodexUrl(?string $baseUrl): string
    {
        $raw = $baseUrl !== null && trim($baseUrl) !== '' ? $baseUrl : 'https://chatgpt.com/backend-api';
        $normalized = rtrim($raw, '/');

        if (str_ends_with($normalized, '/codex/responses')) {
            return $normalized;
        }

        if (str_ends_with($normalized, '/codex')) {
            return $normalized.'/responses';
        }

        return $normalized.'/codex/responses';
    }

    private static function extractAccountId(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Failed to extract accountId from token.');
        }

        $payloadJson = self::decodeBase64Url($parts[1]);
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Failed to extract accountId from token.');
        }

        $claim = $payload['https://api.openai.com/auth'] ?? null;
        $accountId = is_array($claim) ? ($claim['chatgpt_account_id'] ?? null) : null;
        if (! is_string($accountId) || $accountId === '') {
            throw new \RuntimeException('Failed to extract accountId from token.');
        }

        return $accountId;
    }

    private static function decodeBase64Url(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to extract accountId from token.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseSseFrame(string $frame): ?array
    {
        $dataLines = [];
        foreach (preg_split('/\r?\n/', $frame) as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $dataLines[] = ltrim(substr($line, 5));
        }

        if ($dataLines === []) {
            return null;
        }

        $payload = implode("\n", $dataLines);
        if ($payload === '[DONE]') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function clampReasoning(?ThinkingLevel $effort): ?ThinkingLevel
    {
        return $effort === ThinkingLevel::Xhigh ? ThinkingLevel::High : $effort;
    }
}
