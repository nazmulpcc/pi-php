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
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\OpenAI\OpenAIResponsesShared;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\ThinkingLevel;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Usage;

final readonly class OpenAICodexResponsesProvider implements ApiProviderInterface
{
    /**
     * @param  null|callable(Model, Context, OpenAICodexResponsesOptions, array<string, mixed>): iterable<array<string, mixed>>  $transport
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

        try {
            $params = $this->buildParams($model, $context, $providerOptions);
            $nextParams = $providerOptions->onPayload?->__invoke($params, $model);
            if (is_array($nextParams)) {
                $params = $nextParams;
            }

            if (! is_callable($this->transport)) {
                $result = $this->runDefaultTransport($model, $providerOptions, $params);
            } else {
                $result = ($this->transport)($model, $context, $providerOptions, $params);
            }

            $events = is_array($result) && array_key_exists('events', $result) ? $result['events'] : $result;
            OpenAIResponsesShared::processStream($events, $stream, $model);
            $providerOptions->onResponse?->__invoke([
                'status' => is_array($result) && array_key_exists('status', $result) ? $result['status'] : 200,
                'headers' => is_array($result) && array_key_exists('headers', $result) ? $result['headers'] : [],
            ], $model);

            return $stream;
        } catch (\Throwable $error) {
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

            return $stream;
        }
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
     * @return array{events: iterable<array<string, mixed>>, status: int, headers: array<string, string>}
     */
    private function runDefaultTransport(Model $model, OpenAICodexResponsesOptions $options, array $params): array
    {
        $apiKey = $options->apiKey ?: EnvApiKeys::getEnvApiKey('openai-codex') ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('OpenAI Codex API key is required. Pass apiKey explicitly.');
        }

        $accountId = self::extractAccountId($apiKey);
        $url = self::resolveCodexUrl($model->baseUrl);
        $headers = array_merge($model->headers, $options->headers, [
            'Accept' => 'text/event-stream',
            'chatgpt-account-id' => $accountId,
            'originator' => 'pi',
            'OpenAI-Beta' => 'responses=experimental',
        ]);

        $onResponse = null;
        $responseStatus = 200;
        $responseHeaders = [];
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

        $events = $transport->stream('POST', $url, [
            'headers' => $headers,
            'body' => $params,
            'apiKey' => $apiKey,
            'onResponse' => $onResponse,
        ]);

        return [
            'events' => $events,
            'status' => $responseStatus,
            'headers' => $responseHeaders,
        ];
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
