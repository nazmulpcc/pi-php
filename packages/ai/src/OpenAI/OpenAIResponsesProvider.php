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
use Pi\AI\Usage;

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

        try {
            $params = $this->buildParams($model, $context, $providerOptions);
            $nextParams = $providerOptions->onPayload?->__invoke($params, $model);
            if (is_array($nextParams)) {
                $params = $nextParams;
            }

            if (! is_callable($this->transport)) {
                $result = $this->runDefaultTransport($model, $context, $providerOptions, $params);
            } else {
                $result = ($this->transport)($model, $context, $providerOptions, $params);
            }

            $events = is_array($result) && array_key_exists('events', $result) ? $result['events'] : $result;
            $message = OpenAIResponsesShared::processStream($events, $stream, $model);
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
     * @return array{events: array<int, array<string, mixed>>, status: int, headers: array<string, string>}
     */
    private function runDefaultTransport(Model $model, Context $context, ProviderOptions $options, array $params): array
    {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('cURL is required for the default OpenAI Responses transport.');
        }

        $apiKey = $options->apiKey ?: EnvApiKeys::getEnvApiKey('openai') ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('OpenAI API key is required. Set OPENAI_API_KEY or pass apiKey explicitly.');
        }

        $url = rtrim($model->baseUrl, '/').'/responses';
        $events = [];
        $headers = [];
        $status = 0;
        $buffer = '';

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize cURL for OpenAI Responses transport.');
        }

        $requestHeaders = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$apiKey,
        ];

        foreach (array_merge($model->headers, $options->headers) as $name => $value) {
            $requestHeaders[] = sprintf('%s: %s', $name, $value);
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT_MS => $options->timeoutMs ?? 0,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, &$events): int {
                $buffer .= $chunk;

                while (($separator = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $separator);
                    $buffer = substr($buffer, $separator + 2);
                    $event = self::parseSseFrame($frame);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$headers): int {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
                    $status = (int) $matches[1];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        $success = curl_exec($curl);
        if ($success === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new \RuntimeException($error !== '' ? $error : 'Unknown cURL error while calling OpenAI Responses API.');
        }

        if ($buffer !== '') {
            $event = self::parseSseFrame($buffer);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        curl_close($curl);

        return [
            'events' => $events,
            'status' => $status,
            'headers' => $headers,
        ];
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
}
