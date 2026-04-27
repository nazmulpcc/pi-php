<?php

declare(strict_types=1);

namespace Pi\AI\Google;

use Closure;
use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\CacheRetention;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\EnvApiKeys;
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

final readonly class GoogleProvider implements ApiProviderInterface
{
    /**
     * @param  null|callable(Model, Context, GoogleOptions, array<string, mixed>): PromiseInterface<iterable<array<string, mixed>>|array{events: iterable<array<string, mixed>>, status?: int, headers?: array<string, string>}>|iterable<array<string, mixed>>|array{events: iterable<array<string, mixed>>, status?: int, headers?: array<string, string>}  $transport
     */
    public function __construct(
        private ?Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::GOOGLE_GENERATIVE_AI);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof GoogleOptions
            ? $options
            : self::mapToProviderOptions($options);

        PromiseHelper::start(
            function () use ($model, $context, $providerOptions, $stream) {
                $params = $this->buildParams($model, $context, $providerOptions);

                return PromiseHelper::resolve($providerOptions->onPayload?->__invoke($params, $model))
                    ->then(function ($nextParams) use ($model, $context, $providerOptions, $params, $stream) {
                        if (is_array($nextParams)) {
                            $params = $nextParams;
                        }

                        if ($this->transport !== null) {
                            return PromiseHelper::resolve(($this->transport)($model, $context, $providerOptions, $params));
                        }

                        return $this->runDefaultTransport($model, $providerOptions, $params, $stream);
                    })
                    ->then(function ($result) use ($model, $providerOptions, $stream) {
                        if ($result instanceof AssistantMessage) {
                            $stream->end();

                            return null;
                        }

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

    /**
     * @return array{
     *   blocks: array<int, TextContent|ThinkingContent|ToolCall>,
     *   currentBlock: TextContent|ThinkingContent|ToolCall|null,
     *   currentBlockIndex: ?int,
     *   usage: Usage,
     *   stopReason: StopReason,
     *   responseId: ?string,
     *   errorMessage: ?string,
     *   toolCallSeen: bool
     * }
     */
    private function initializeStreamState(AssistantMessageEventStream $stream, Model $model): array
    {
        $output = $this->createOutput($model);
        $stream->push(new StartEvent($output));

        return [
            'blocks' => [],
            'currentBlock' => null,
            'currentBlockIndex' => null,
            'usage' => $output->usage,
            'stopReason' => StopReason::Stop,
            'responseId' => null,
            'errorMessage' => null,
            'toolCallSeen' => false,
        ];
    }

    /**
     * @param  array{
     *   blocks: array<int, TextContent|ThinkingContent|ToolCall>,
     *   currentBlock: TextContent|ThinkingContent|ToolCall|null,
     *   currentBlockIndex: ?int,
     *   usage: Usage,
     *   stopReason: StopReason,
     *   responseId: ?string,
     *   errorMessage: ?string,
     *   toolCallSeen: bool
     * }  $state
     * @param  array<string, mixed>  $event
     */
    private function processStreamEvent(array &$state, array $event, AssistantMessageEventStream $stream, Model $model): void
    {
        $state['responseId'] ??= $event['responseId'] ?? $event['id'] ?? null;
        $output = $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);

        if (isset($event['usageMetadata']) && is_array($event['usageMetadata'])) {
            $state['usage'] = $this->parseUsage($event['usageMetadata'], $model);
            $output = $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);
        }

        $candidate = isset($event['candidates']) && is_array($event['candidates']) ? ($event['candidates'][0] ?? null) : null;
        if (! is_array($candidate)) {
            return;
        }

        if (isset($candidate['finishReason']) && is_string($candidate['finishReason'])) {
            $state['stopReason'] = GoogleShared::mapStopReason($candidate['finishReason']);
        }

        if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
            foreach ($candidate['content']['parts'] as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (isset($part['text']) && is_string($part['text'])) {
                    $isThinking = ($part['thought'] ?? false) === true;
                    $signature = isset($part['thoughtSignature']) && is_string($part['thoughtSignature']) ? $part['thoughtSignature'] : null;

                    if ($state['currentBlock'] === null || ($isThinking && ! $state['currentBlock'] instanceof ThinkingContent) || (! $isThinking && ! $state['currentBlock'] instanceof TextContent)) {
                        $this->finishCurrentBlock($state['currentBlock'], $state['currentBlockIndex'], $stream, $output);

                        if ($isThinking) {
                            $state['currentBlock'] = new ThinkingContent('', $signature);
                            $state['blocks'][] = $state['currentBlock'];
                            $state['currentBlockIndex'] = array_key_last($state['blocks']);
                            $stream->push(new ThinkingStartEvent($state['currentBlockIndex'], $output));
                        } else {
                            $state['currentBlock'] = new TextContent('', $signature);
                            $state['blocks'][] = $state['currentBlock'];
                            $state['currentBlockIndex'] = array_key_last($state['blocks']);
                            $stream->push(new TextStartEvent($state['currentBlockIndex'], $output));
                        }
                    }

                    if ($state['currentBlock'] instanceof ThinkingContent) {
                        $state['currentBlock'] = new ThinkingContent(
                            $state['currentBlock']->thinking.$part['text'],
                            is_string($signature) && $signature !== '' ? $signature : $state['currentBlock']->thinkingSignature,
                        );
                        $state['blocks'][$state['currentBlockIndex']] = $state['currentBlock'];
                        $output = $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);
                        $stream->push(new ThinkingDeltaEvent($state['currentBlockIndex'], $part['text'], $output));
                    } elseif ($state['currentBlock'] instanceof TextContent) {
                        $state['currentBlock'] = new TextContent(
                            $state['currentBlock']->text.$part['text'],
                            is_string($signature) && $signature !== '' ? $signature : $state['currentBlock']->textSignature,
                        );
                        $state['blocks'][$state['currentBlockIndex']] = $state['currentBlock'];
                        $output = $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);
                        $stream->push(new TextDeltaEvent($state['currentBlockIndex'], $part['text'], $output));
                    }
                }

                if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                    $state['toolCallSeen'] = true;

                    $this->finishCurrentBlock($state['currentBlock'], $state['currentBlockIndex'], $stream, $output);
                    $state['currentBlock'] = null;
                    $state['currentBlockIndex'] = null;

                    $functionCall = $part['functionCall'];
                    $providedId = isset($functionCall['id']) && is_string($functionCall['id']) ? $functionCall['id'] : '';
                    $name = isset($functionCall['name']) && is_string($functionCall['name']) ? $functionCall['name'] : '';
                    $arguments = isset($functionCall['args']) && is_array($functionCall['args']) ? $functionCall['args'] : [];
                    $toolCallId = $providedId !== '' ? $providedId : sprintf('%s_%s', $name !== '' ? $name : 'tool', uniqid('', true));
                    $thoughtSignature = isset($part['thoughtSignature']) && is_string($part['thoughtSignature']) ? $part['thoughtSignature'] : null;

                    $toolCall = new ToolCall($toolCallId, $name, [], $thoughtSignature);
                    $state['blocks'][] = $toolCall;
                    $index = array_key_last($state['blocks']);
                    $output = $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);
                    $stream->push(new ToolCallStartEvent($index, $output));

                    $toolCall = new ToolCall($toolCallId, $name, JsonParse::parseStreamingJson(json_encode($arguments, JSON_THROW_ON_ERROR)), $thoughtSignature);
                    $state['blocks'][$index] = $toolCall;
                    $output = $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);
                    $stream->push(new ToolCallDeltaEvent($index, json_encode($arguments, JSON_THROW_ON_ERROR), $output));
                    $stream->push(new ToolCallEndEvent($index, $toolCall, $output));
                }
            }
        }

        if ($candidate['finishReason'] ?? null) {
            $state['stopReason'] = GoogleShared::mapStopReason(is_string($candidate['finishReason']) ? $candidate['finishReason'] : null);
        }

        if ($state['stopReason'] === StopReason::ToolUse || $state['toolCallSeen']) {
            $state['stopReason'] = StopReason::ToolUse;
        }
    }

    /**
     * @param  array{
     *   blocks: array<int, TextContent|ThinkingContent|ToolCall>,
     *   currentBlock: TextContent|ThinkingContent|ToolCall|null,
     *   currentBlockIndex: ?int,
     *   usage: Usage,
     *   stopReason: StopReason,
     *   responseId: ?string,
     *   errorMessage: ?string,
     *   toolCallSeen: bool
     * }  $state
     */
    private function finalizeStreamState(array &$state, AssistantMessageEventStream $stream, Model $model): AssistantMessage
    {
        $output = $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);
        $this->finishCurrentBlock($state['currentBlock'], $state['currentBlockIndex'], $stream, $output);

        return $this->snapshot($model, $state['blocks'], $state['usage'], $state['stopReason'], $state['responseId'], $state['errorMessage']);
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = SimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);
        $reasoning = $options?->reasoning === null ? null : SimpleOptions::clampReasoning($options->reasoning);

        if ($reasoning === null) {
            return $this->stream($model, $context, new GoogleOptions(
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
                thinkingEnabled: false,
            ));
        }

        if (GoogleShared::isGemini3ProModel($model) || GoogleShared::isGemini3FlashModel($model) || GoogleShared::isGemma4Model($model)) {
            return $this->stream($model, $context, new GoogleOptions(
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
                thinkingEnabled: true,
                thinkingLevel: GoogleShared::getThinkingLevel($reasoning, $model),
            ));
        }

        return $this->stream($model, $context, new GoogleOptions(
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
            thinkingEnabled: true,
            thinkingBudgetTokens: GoogleShared::getGoogleBudget($model, $reasoning, $options?->thinkingBudgets ?? []),
        ));
    }

    private function buildParams(Model $model, Context $context, GoogleOptions $options): array
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

        if ($this->shouldEnableThinking($model, $options)) {
            $config['thinkingConfig'] = array_filter([
                'includeThoughts' => true,
                'thinkingLevel' => $options->thinkingLevel,
                'thinkingBudget' => $options->thinkingBudgetTokens,
            ], static fn (mixed $value): bool => $value !== null);
        } elseif ($model->reasoning && $options->thinkingEnabled === false) {
            $config['thinkingConfig'] = $this->getDisabledThinkingConfig($model);
        }

        if ($config !== []) {
            $params['config'] = $config;
        }

        return $params;
    }

    private function shouldEnableThinking(Model $model, GoogleOptions $options): bool
    {
        if (! $model->reasoning) {
            return false;
        }

        if ($options->thinkingEnabled !== null) {
            return $options->thinkingEnabled;
        }

        return $options->thinkingLevel !== null || $options->thinkingBudgetTokens !== null;
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
     * @return PromiseInterface<AssistantMessage|array{events: iterable<array<string, mixed>>, status: int, headers: array<string, string>}>
     */
    private function runDefaultTransport(Model $model, GoogleOptions $options, array $params, AssistantMessageEventStream $stream): PromiseInterface
    {
        $apiKey = $options->apiKey ?: EnvApiKeys::getEnvApiKey($model->provider->value) ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException(sprintf('No API key for provider: %s', $model->provider->value));
        }

        $baseUrl = rtrim($model->baseUrl !== '' ? $model->baseUrl : 'https://generativelanguage.googleapis.com/v1beta', '/');
        $url = $baseUrl.'/models/'.rawurlencode($model->id).':streamGenerateContent?alt=sse';

        $transport = new HttpTransport(
            signal: $options->signal,
            timeoutMs: $options->timeoutMs,
            maxRetries: $options->maxRetries,
            maxRetryDelayMs: $options->maxRetryDelayMs,
        );

        $headers = array_merge($model->headers, $options->headers);
        $headers['x-goog-api-key'] = $apiKey;

        $onResponse = $options->onResponse !== null
            ? static function (array $response) use ($options, $model): void {
                $options->onResponse->__invoke([
                    'status' => $response['status'],
                    'headers' => $response['headers'],
                ], $model);
            }
        : null;

        $state = $this->initializeStreamState($stream, $model);

        return $transport->stream('POST', $url, [
            'headers' => $headers,
            'body' => $params,
            'apiKey' => null,
            'onResponse' => $onResponse,
            'onEvent' => function (array $event) use (&$state, $stream, $model): void {
                $this->processStreamEvent($state, $event, $stream, $model);
            },
        ])->then(function () use (&$state, $stream, $model): AssistantMessage {
            $output = $this->finalizeStreamState($state, $stream, $model);
            $stream->push(new DoneEvent($output->stopReason, $output));

            return $output;
        });
    }

    private static function mapToProviderOptions(?StreamOptions $options): GoogleOptions
    {
        return new GoogleOptions(
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
