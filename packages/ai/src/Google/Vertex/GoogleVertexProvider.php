<?php

declare(strict_types=1);

namespace Pi\AI\Google\Vertex;

use Closure;
use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\CacheRetention;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Event\StartEvent;
use Pi\AI\Event\TextDeltaEvent;
use Pi\AI\Event\TextEndEvent;
use Pi\AI\Event\TextStartEvent;
use Pi\AI\Event\ThinkingDeltaEvent;
use Pi\AI\Event\ThinkingEndEvent;
use Pi\AI\Event\ThinkingStartEvent;
use Pi\AI\Event\ToolCallDeltaEvent;
use Pi\AI\Event\ToolCallEndEvent;
use Pi\AI\Event\ToolCallStartEvent;
use Pi\AI\Google\GoogleShared;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Support\JsonParse;
use Pi\AI\Support\PromiseHelper;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\SimpleOptions;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Transport\ProviderError;
use Pi\AI\Usage;
use Pi\AI\UsageCost;
use React\Promise\PromiseInterface;

final readonly class GoogleVertexProvider implements ApiProviderInterface
{
    private const GCP_VERTEX_CREDENTIALS_MARKER = 'gcp-vertex-credentials';

    /**
     * @param  null|callable(Model, Context, GoogleVertexOptions, array<string, mixed>): PromiseInterface<iterable<array<string, mixed>>|array{events: iterable<array<string, mixed>>, status?: int, headers?: array<string, string>}>|iterable<array<string, mixed>>|array{events: iterable<array<string, mixed>>, status?: int, headers?: array<string, string>}  $transport
     */
    public function __construct(
        private ?Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::GOOGLE_VERTEX);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof GoogleVertexOptions
            ? $options
            : self::mapToProviderOptions($options);

        PromiseHelper::start(
            function () use ($model, $context, $providerOptions, $stream) {
                $params = $this->buildParams($model, $context, $providerOptions);

                return PromiseHelper::resolve($providerOptions->onPayload?->__invoke($params, $model))
                    ->then(function ($nextParams) use ($model, $context, $providerOptions, $params) {
                        if (is_array($nextParams)) {
                            $params = $nextParams;
                        }

                        if ($this->transport !== null) {
                            return PromiseHelper::resolve(($this->transport)($model, $context, $providerOptions, $params));
                        }

                        return $this->runDefaultTransport($model, $providerOptions, $params);
                    })
                    ->then(function ($result) use ($model, $providerOptions, $stream) {
                        $events = is_array($result) && array_key_exists('events', $result) ? $result['events'] : $result;
                        $status = is_array($result) && array_key_exists('status', $result) ? (int) $result['status'] : 200;
                        $headers = is_array($result) && array_key_exists('headers', $result) && is_array($result['headers']) ? $result['headers'] : [];

                        $output = $this->createOutput($model);
                        $stream->push(new StartEvent($output));

                        $blocks = [];
                        $currentBlock = null;
                        $currentBlockIndex = null;
                        $usage = $output->usage;
                        $stopReason = StopReason::Stop;
                        $responseId = null;
                        $errorMessage = null;
                        $toolCallSeen = false;

                        foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $responseId ??= $event['responseId'] ?? $event['id'] ?? null;

                if (isset($event['usageMetadata']) && is_array($event['usageMetadata'])) {
                    $usage = $this->parseUsage($event['usageMetadata'], $model);
                    $output = $this->snapshot($model, $output->content, $usage, $stopReason, $responseId, $errorMessage);
                }

                $candidate = isset($event['candidates']) && is_array($event['candidates']) ? ($event['candidates'][0] ?? null) : null;
                if (! is_array($candidate)) {
                    continue;
                }

                if (isset($candidate['finishReason']) && is_string($candidate['finishReason'])) {
                    $stopReason = GoogleShared::mapStopReason($candidate['finishReason']);
                }

                if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
                    foreach ($candidate['content']['parts'] as $part) {
                        if (! is_array($part)) {
                            continue;
                        }

                        if (isset($part['text']) && is_string($part['text'])) {
                            $isThinking = ($part['thought'] ?? false) === true;
                            $signature = isset($part['thoughtSignature']) && is_string($part['thoughtSignature']) ? $part['thoughtSignature'] : null;

                            if ($currentBlock === null || ($isThinking && ! $currentBlock instanceof ThinkingContent) || (! $isThinking && ! $currentBlock instanceof TextContent)) {
                                $this->finishCurrentBlock($currentBlock, $currentBlockIndex, $stream, $output);

                                if ($isThinking) {
                                    $currentBlock = new ThinkingContent('', $signature);
                                    $blocks[] = $currentBlock;
                                    $currentBlockIndex = array_key_last($blocks);
                                    $stream->push(new ThinkingStartEvent($currentBlockIndex, $output));
                                } else {
                                    $currentBlock = new TextContent('', $signature);
                                    $blocks[] = $currentBlock;
                                    $currentBlockIndex = array_key_last($blocks);
                                    $stream->push(new TextStartEvent($currentBlockIndex, $output));
                                }
                            }

                            if ($currentBlock instanceof ThinkingContent) {
                                $currentBlock = new ThinkingContent(
                                    $currentBlock->thinking.$part['text'],
                                    is_string($signature) && $signature !== '' ? $signature : $currentBlock->thinkingSignature,
                                );
                                $blocks[$currentBlockIndex] = $currentBlock;
                                $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                                $stream->push(new ThinkingDeltaEvent($currentBlockIndex, $part['text'], $output));
                            } elseif ($currentBlock instanceof TextContent) {
                                $currentBlock = new TextContent(
                                    $currentBlock->text.$part['text'],
                                    is_string($signature) && $signature !== '' ? $signature : $currentBlock->textSignature,
                                );
                                $blocks[$currentBlockIndex] = $currentBlock;
                                $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                                $stream->push(new TextDeltaEvent($currentBlockIndex, $part['text'], $output));
                            }
                        }

                        if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                            $toolCallSeen = true;

                            $this->finishCurrentBlock($currentBlock, $currentBlockIndex, $stream, $output);
                            $currentBlock = null;
                            $currentBlockIndex = null;

                            $functionCall = $part['functionCall'];
                            $providedId = isset($functionCall['id']) && is_string($functionCall['id']) ? $functionCall['id'] : '';
                            $name = isset($functionCall['name']) && is_string($functionCall['name']) ? $functionCall['name'] : '';
                            $arguments = isset($functionCall['args']) && is_array($functionCall['args']) ? $functionCall['args'] : [];
                            $toolCallId = $providedId !== '' ? $providedId : sprintf('%s_%s', $name !== '' ? $name : 'tool', uniqid('', true));
                            $thoughtSignature = isset($part['thoughtSignature']) && is_string($part['thoughtSignature']) ? $part['thoughtSignature'] : null;

                            $toolCall = new ToolCall($toolCallId, $name, [], $thoughtSignature);
                            $blocks[] = $toolCall;
                            $index = array_key_last($blocks);
                            $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                            $stream->push(new ToolCallStartEvent($index, $output));

                            $toolCall = new ToolCall($toolCallId, $name, JsonParse::parseStreamingJson(json_encode($arguments, JSON_THROW_ON_ERROR)), $thoughtSignature);
                            $blocks[$index] = $toolCall;
                            $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                            $stream->push(new ToolCallDeltaEvent($index, json_encode($arguments, JSON_THROW_ON_ERROR), $output));
                            $stream->push(new ToolCallEndEvent($index, $toolCall, $output));
                        }
                    }
                }

                if ($candidate['finishReason'] ?? null) {
                    $stopReason = GoogleShared::mapStopReason(is_string($candidate['finishReason']) ? $candidate['finishReason'] : null);
                }

                if ($stopReason === StopReason::ToolUse || $toolCallSeen) {
                    $stopReason = StopReason::ToolUse;
                }

                $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                        }

                        $this->finishCurrentBlock($currentBlock, $currentBlockIndex, $stream, $output);

                        if ($providerOptions->signal?->isCancelled()) {
                            throw new ProviderError('Request was aborted', 0, 'aborted');
                        }

                        if ($stopReason === StopReason::Aborted) {
                            throw new ProviderError('Request was aborted', 0, 'aborted');
                        }

                        if ($stopReason === StopReason::Error) {
                            throw new ProviderError($errorMessage ?: 'Provider returned an error stop reason', $status);
                        }

                        $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                        $stream->push(new DoneEvent($stopReason, $output));
                        $stream->end();

                        if ($this->transport !== null && $providerOptions->onResponse !== null) {
                            return PromiseHelper::resolve($providerOptions->onResponse->__invoke([
                                'status' => $status,
                                'headers' => $headers,
                            ], $model));
                        }

                        return null;
                    });
            },
            function (\Throwable $error) use ($stream, $providerOptions, $model): void {
                $output = $this->createOutput($model);
                $output = new AssistantMessage(
                    content: $output->content,
                    api: $output->api,
                    provider: $output->provider,
                    model: $output->model,
                    usage: $output->usage,
                    stopReason: $providerOptions->signal?->isCancelled() ? StopReason::Aborted : StopReason::Error,
                    timestamp: $output->timestamp,
                    responseId: $output->responseId,
                    errorMessage: $error->getMessage(),
                );
                $stream->push(new ErrorEvent($output->stopReason, $output));
                $stream->end($output);
            },
        );

        return $stream;
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = SimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);
        $reasoning = $options?->reasoning === null ? null : SimpleOptions::clampReasoning($options->reasoning);

        if ($reasoning === null) {
            return $this->stream($model, $context, new GoogleVertexOptions(
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
                thinking: ['enabled' => false],
            ));
        }

        if (GoogleShared::isGemini3ProModel($model) || GoogleShared::isGemini3FlashModel($model) || GoogleShared::isGemma4Model($model)) {
            return $this->stream($model, $context, new GoogleVertexOptions(
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
                thinking: [
                    'enabled' => true,
                    'level' => GoogleShared::getThinkingLevel($reasoning, $model),
                ],
            ));
        }

        return $this->stream($model, $context, new GoogleVertexOptions(
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
            thinking: [
                'enabled' => true,
                'budgetTokens' => GoogleShared::getGoogleBudget($model, $reasoning, $options?->thinkingBudgets ?? []),
            ],
        ));
    }

    private function buildParams(Model $model, Context $context, GoogleVertexOptions $options): array
    {
        $params = [
            'model' => $model->id,
            'contents' => GoogleShared::convertMessages($model, $context),
        ];

        $config = [];
        if ($options->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }
        if ($options->maxTokens !== null) {
            $config['maxOutputTokens'] = $options->maxTokens;
        }
        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $config['systemInstruction'] = ['parts' => [['text' => SanitizeUnicode::sanitizeSurrogates($context->systemPrompt)]]];
        }
        if ($context->tools !== []) {
            $config['tools'] = GoogleShared::convertTools($context->tools);
            if ($options->toolChoice !== null) {
                $config['toolConfig'] = [
                    'functionCallingConfig' => [
                        'mode' => GoogleShared::mapToolChoice($options->toolChoice),
                    ],
                ];
            }
        }

        if ($options->thinking !== null && ($options->thinking['enabled'] ?? false) && $model->reasoning) {
            $thinkingConfig = ['includeThoughts' => true];
            if (isset($options->thinking['level']) && is_string($options->thinking['level'])) {
                $thinkingConfig['thinkingLevel'] = $options->thinking['level'];
            } elseif (isset($options->thinking['budgetTokens']) && is_int($options->thinking['budgetTokens'])) {
                $thinkingConfig['thinkingBudget'] = $options->thinking['budgetTokens'];
            }
            $config['thinkingConfig'] = $thinkingConfig;
        } elseif ($model->reasoning && $options->thinking !== null && ! ($options->thinking['enabled'] ?? false)) {
            $config['thinkingConfig'] = $this->getDisabledThinkingConfig($model);
        }

        if ($config !== []) {
            $params['config'] = $config;
        }

        return $params;
    }

    private function getDisabledThinkingConfig(Model $model): array
    {
        if (GoogleShared::isGemini3ProModel($model)) {
            return ['thinkingLevel' => 'LOW'];
        }

        if (GoogleShared::isGemini3FlashModel($model) || GoogleShared::isGemma4Model($model)) {
            return ['thinkingLevel' => 'MINIMAL'];
        }

        return ['thinkingBudget' => 0];
    }

    private function createOutput(Model $model): AssistantMessage
    {
        return new AssistantMessage(
            content: [],
            api: $model->api,
            provider: $model->provider,
            model: $model->id,
            usage: Usage::zero(),
            stopReason: StopReason::Stop,
            timestamp: time(),
        );
    }

    /**
     * @param  array<int, mixed>  $content
     */
    private function snapshot(Model $model, array $content, Usage $usage, StopReason $stopReason, ?string $responseId, ?string $errorMessage): AssistantMessage
    {
        return new AssistantMessage(
            content: $content,
            api: $model->api,
            provider: $model->provider,
            model: $model->id,
            usage: $usage,
            stopReason: $stopReason,
            timestamp: time(),
            responseId: $responseId,
            errorMessage: $errorMessage,
        );
    }

    private function finishCurrentBlock(mixed $block, ?int $contentIndex, AssistantMessageEventStream $stream, AssistantMessage $output): void
    {
        if ($contentIndex === null) {
            return;
        }

        if ($block instanceof TextContent) {
            $stream->push(new TextEndEvent($contentIndex, $block->text, $output));
        } elseif ($block instanceof ThinkingContent) {
            $stream->push(new ThinkingEndEvent($contentIndex, $block->thinking, $output));
        }
    }

    private function parseUsage(array $usageMetadata, Model $model): Usage
    {
        $usage = new Usage(
            input: max(0, (int) ($usageMetadata['promptTokenCount'] ?? 0) - (int) ($usageMetadata['cachedContentTokenCount'] ?? 0)),
            output: (int) ($usageMetadata['candidatesTokenCount'] ?? 0) + (int) ($usageMetadata['thoughtsTokenCount'] ?? 0),
            cacheRead: (int) ($usageMetadata['cachedContentTokenCount'] ?? 0),
            cacheWrite: 0,
            totalTokens: (int) ($usageMetadata['totalTokenCount'] ?? 0),
            cost: new UsageCost,
        );
        Models::calculateCost($model, $usage);

        return $usage;
    }

    /**
     * @return PromiseInterface<array{events: iterable<array<string, mixed>>, status: int, headers: array<string, string>}>
     */
    private function runDefaultTransport(Model $model, GoogleVertexOptions $options, array $params): PromiseInterface
    {
        $project = $this->resolveProject($options);
        $location = $this->resolveLocation($options);
        $apiKey = $this->resolveApiKey($options);

        $baseUrl = rtrim($model->baseUrl !== '' ? $model->baseUrl : sprintf('https://%s-aiplatform.googleapis.com', $location), '/');

        if ($apiKey !== null && $apiKey !== '') {
            $url = sprintf(
                '%s/v1/projects/%s/locations/%s/publishers/google/models/%s:streamGenerateContent?key=%s',
                $baseUrl,
                rawurlencode($project),
                rawurlencode($location),
                rawurlencode($model->id),
                rawurlencode($apiKey),
            );
        } else {
            $url = sprintf(
                '%s/v1/projects/%s/locations/%s/publishers/google/models/%s:streamGenerateContent',
                $baseUrl,
                rawurlencode($project),
                rawurlencode($location),
                rawurlencode($model->id),
            );
        }

        $headers = array_merge($model->headers, $options->headers);

        return $this->resolveAccessToken()->then(function (?string $accessToken) use ($headers, $options, $model, $params, $url) {
            if ($accessToken !== null && $accessToken !== '') {
                $headers['Authorization'] = 'Bearer '.$accessToken;
            }

            $transport = new HttpTransport(
                signal: $options->signal,
                timeoutMs: $options->timeoutMs,
                maxRetries: $options->maxRetries,
                maxRetryDelayMs: $options->maxRetryDelayMs,
            );

            $onResponse = $options->onResponse !== null
                ? static function (array $response) use ($options, $model): mixed {
                    return $options->onResponse->__invoke([
                        'status' => $response['status'],
                        'headers' => $response['headers'],
                    ], $model);
                }
                : null;

            return $transport->stream('POST', $url, [
                'headers' => $headers,
                'body' => $params,
                'apiKey' => null,
                'onResponse' => $onResponse,
            ])->then(
                static fn ($events): array => [
                    'events' => $events,
                    'status' => 200,
                    'headers' => [],
                ],
            );
        });
    }

    private function resolveApiKey(GoogleVertexOptions $options): ?string
    {
        $key = $options->apiKey ?: (getenv('GOOGLE_CLOUD_API_KEY') ?: null);
        if ($key === null || trim($key) === '' || $key === self::GCP_VERTEX_CREDENTIALS_MARKER || preg_match('/^<[^>]+>$/', $key) === 1) {
            return null;
        }

        return $key;
    }

    private function resolveProject(GoogleVertexOptions $options): string
    {
        $project = $options->project ?: (getenv('GOOGLE_CLOUD_PROJECT') ?: (getenv('GCLOUD_PROJECT') ?: null));
        if ($project === null || trim($project) === '') {
            throw new \RuntimeException('Vertex AI requires a project ID. Set GOOGLE_CLOUD_PROJECT/GCLOUD_PROJECT or pass project in options.');
        }

        return $project;
    }

    private function resolveLocation(GoogleVertexOptions $options): string
    {
        $location = $options->location ?: (getenv('GOOGLE_CLOUD_LOCATION') ?: null);
        if ($location === null || trim($location) === '') {
            throw new \RuntimeException('Vertex AI requires a location. Set GOOGLE_CLOUD_LOCATION or pass location in options.');
        }

        return $location;
    }

    private function resolveAccessToken(): PromiseInterface
    {
        $adcPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: null;
        if ($adcPath === null || ! file_exists($adcPath)) {
            return PromiseHelper::resolve(null);
        }

        $contents = file_get_contents($adcPath);
        if ($contents === false) {
            return PromiseHelper::resolve(null);
        }

        $credentials = json_decode($contents, true);
        if (! is_array($credentials)) {
            return PromiseHelper::resolve(null);
        }

        if (isset($credentials['refresh_token'])) {
            return $this->exchangeRefreshToken($credentials);
        }

        if (isset($credentials['private_key'], $credentials['client_email'])) {
            return $this->createServiceAccountToken($credentials);
        }

        return PromiseHelper::resolve(null);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function exchangeRefreshToken(array $credentials): PromiseInterface
    {
        $tokenUri = is_string($credentials['token_uri'] ?? null) ? $credentials['token_uri'] : 'https://oauth2.googleapis.com/token';
        $clientId = is_string($credentials['client_id'] ?? null) ? $credentials['client_id'] : '';
        $clientSecret = is_string($credentials['client_secret'] ?? null) ? $credentials['client_secret'] : '';
        $refreshToken = is_string($credentials['refresh_token'] ?? null) ? $credentials['refresh_token'] : '';

        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            return PromiseHelper::resolve(null);
        }

        $transport = new HttpTransport;
        return $transport->request('POST', $tokenUri, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ],
        ])->then(
            static function ($response): ?string {
                $data = json_decode($response->body, true);
                if (is_array($data) && isset($data['access_token']) && is_string($data['access_token'])) {
                    return $data['access_token'];
                }

                return null;
            },
            static fn (): ?string => null,
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function createServiceAccountToken(array $credentials): PromiseInterface
    {
        $clientEmail = is_string($credentials['client_email'] ?? null) ? $credentials['client_email'] : '';
        $privateKey = is_string($credentials['private_key'] ?? null) ? $credentials['private_key'] : '';
        $tokenUri = is_string($credentials['token_uri'] ?? null) ? $credentials['token_uri'] : 'https://oauth2.googleapis.com/token';

        if ($clientEmail === '' || $privateKey === '') {
            return PromiseHelper::resolve(null);
        }

        $now = time();
        $jwtHeader = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $jwtClaim = json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $jwtHeaderB64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($jwtHeader));
        $jwtClaimB64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($jwtClaim));
        $signatureInput = $jwtHeaderB64.'.'.$jwtClaimB64;

        $signature = '';
        if (! openssl_sign($signatureInput, $signature, $privateKey, 'sha256')) {
            return PromiseHelper::resolve(null);
        }

        $jwt = $signatureInput.'.'.str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $transport = new HttpTransport;
        return $transport->request('POST', $tokenUri, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ])->then(
            static function ($response): ?string {
                $data = json_decode($response->body, true);
                if (is_array($data) && isset($data['access_token']) && is_string($data['access_token'])) {
                    return $data['access_token'];
                }

                return null;
            },
            static fn (): ?string => null,
        );
    }

    private static function mapToProviderOptions(?StreamOptions $options): GoogleVertexOptions
    {
        return new GoogleVertexOptions(
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
