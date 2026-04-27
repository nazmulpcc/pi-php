<?php

declare(strict_types=1);

namespace Pi\AI\Anthropic;

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
use Pi\AI\ThinkingLevel;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Transport\ProviderError;
use Pi\AI\Usage;

final readonly class AnthropicProvider implements ApiProviderInterface
{
    public function __construct(
        private ?\Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::ANTHROPIC_MESSAGES);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof AnthropicOptions
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

                        $apiKey = $providerOptions->apiKey ?: EnvApiKeys::getEnvApiKey($model->provider->value) ?: null;
                        if ($apiKey === null || $apiKey === '') {
                            throw new \RuntimeException(sprintf('No API key for provider: %s', $model->provider->value));
                        }

                        $url = rtrim($model->baseUrl, '/').'/v1/messages';
                        $headers = array_merge($model->headers, $providerOptions->headers);
                        $headers['anthropic-version'] = '2023-06-01';
                        $headers['content-type'] = 'application/json';

                        $transport = new HttpTransport(
                            signal: $providerOptions->signal,
                            timeoutMs: $providerOptions->timeoutMs,
                            maxRetries: $providerOptions->maxRetries,
                            maxRetryDelayMs: $providerOptions->maxRetryDelayMs,
                        );

                        $state = $this->initializeStreamState($stream, $model);

                        return $transport->stream('POST', $url, [
                            'headers' => $headers,
                            'body' => $params,
                            'apiKey' => $apiKey,
                            'onResponse' => $providerOptions->onResponse !== null
                                ? static function (array $response) use ($providerOptions, $model): mixed {
                                    return $providerOptions->onResponse?->__invoke([
                                        'status' => $response['status'],
                                        'headers' => $response['headers'],
                                    ], $model);
                                }
                                : null,
                            'onEvent' => function (array $event) use (&$state, $stream, $model): void {
                                $this->processStreamEvent($state, $event, $stream, $model);
                            },
                        ])->then(function () use (&$state, $stream, $model): AssistantMessage {
                            $output = $this->finalizeStreamState($state, $stream, $model);
                            $stream->push(new DoneEvent($output->stopReason, $output));

                            return $output;
                        });
                    })
                    ->then(function ($events) use ($model, $providerOptions, $stream) {
                        if ($events instanceof AssistantMessage) {
                            $stream->end();

                            return null;
                        }

                        $output = $this->createOutput($model);
                        $stream->push(new StartEvent($output));

                        $blocks = [];
                        $blockIndices = [];
                        $toolScratch = [];

                        foreach ($events as $event) {
                            if (! is_array($event)) {
                                continue;
                            }

                            $eventType = $event['_eventType'] ?? null;
                            unset($event['_eventType']);

                            if ($eventType === 'message_start') {
                                $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $event['message']['id'] ?? null, $output->errorMessage);
                                if (isset($event['message']['usage']) && is_array($event['message']['usage'])) {
                                    $usage = new Usage(
                                        input: $event['message']['usage']['input_tokens'] ?? 0,
                                        output: $event['message']['usage']['output_tokens'] ?? 0,
                                        cacheRead: $event['message']['usage']['cache_read_input_tokens'] ?? 0,
                                        cacheWrite: $event['message']['usage']['cache_creation_input_tokens'] ?? 0,
                                        totalTokens: 0,
                                        cost: $output->usage->cost,
                                    );
                                    $usage = new Usage(
                                        input: $usage->input,
                                        output: $usage->output,
                                        cacheRead: $usage->cacheRead,
                                        cacheWrite: $usage->cacheWrite,
                                        totalTokens: $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite,
                                        cost: $output->usage->cost,
                                    );
                                    Models::calculateCost($model, $usage);
                                    $output = $this->snapshot($model, $blocks, $usage, $output->stopReason, $output->responseId, $output->errorMessage);
                                }
                            } elseif ($eventType === 'content_block_start') {
                                $index = $event['index'] ?? 0;
                                $contentBlock = $event['content_block'] ?? [];
                                $blockType = $contentBlock['type'] ?? null;

                                if ($blockType === 'text') {
                                    $blocks[] = new TextContent('');
                                    $blockIndices[$index] = count($blocks) - 1;
                                    $stream->push(new TextStartEvent($blockIndices[$index], $output));
                                } elseif ($blockType === 'thinking') {
                                    $blocks[] = new ThinkingContent('', '');
                                    $blockIndices[$index] = count($blocks) - 1;
                                    $stream->push(new ThinkingStartEvent($blockIndices[$index], $output));
                                } elseif ($blockType === 'redacted_thinking') {
                                    $blocks[] = new ThinkingContent('[Reasoning redacted]', $contentBlock['data'] ?? '', true);
                                    $blockIndices[$index] = count($blocks) - 1;
                                    $stream->push(new ThinkingStartEvent($blockIndices[$index], $output));
                                } elseif ($blockType === 'tool_use') {
                                    $toolIdx = count($blocks);
                                    $blocks[] = new ToolCall(
                                        $contentBlock['id'] ?? '',
                                        $contentBlock['name'] ?? '',
                                        $contentBlock['input'] ?? [],
                                    );
                                    $toolScratch[$toolIdx] = ['partialJson' => ''];
                                    $blockIndices[$index] = $toolIdx;
                                    $stream->push(new ToolCallStartEvent($blockIndices[$index], $output));
                                }
                            } elseif ($eventType === 'content_block_delta') {
                                $index = $event['index'] ?? 0;
                                $delta = $event['delta'] ?? [];
                                $blockIdx = $blockIndices[$index] ?? null;

                                if ($blockIdx === null) {
                                    continue;
                                }

                                $block = $blocks[$blockIdx];

                                if ($delta['type'] === 'text_delta' && $block instanceof TextContent) {
                                    $blocks[$blockIdx] = new TextContent($block->text.$delta['text']);
                                    $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
                                    $stream->push(new TextDeltaEvent($blockIdx, $delta['text'], $output));
                                } elseif ($delta['type'] === 'thinking_delta' && $block instanceof ThinkingContent) {
                                    $blocks[$blockIdx] = new ThinkingContent($block->thinking.$delta['thinking'], $block->thinkingSignature);
                                    $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
                                    $stream->push(new ThinkingDeltaEvent($blockIdx, $delta['thinking'], $output));
                                } elseif ($delta['type'] === 'input_json_delta' && $block instanceof ToolCall) {
                                    $toolScratch[$blockIdx]['partialJson'] = ($toolScratch[$blockIdx]['partialJson'] ?? '').$delta['partial_json'];
                                    $blocks[$blockIdx] = new ToolCall(
                                        $block->id,
                                        $block->name,
                                        JsonParse::parseStreamingJson($toolScratch[$blockIdx]['partialJson']),
                                        $block->thoughtSignature,
                                    );
                                    $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
                                    $stream->push(new ToolCallDeltaEvent($blockIdx, $delta['partial_json'], $output));
                                } elseif ($delta['type'] === 'signature_delta' && $block instanceof ThinkingContent) {
                                    $blocks[$blockIdx] = new ThinkingContent($block->thinking, $block->thinkingSignature.$delta['signature']);
                                }
                            } elseif ($eventType === 'content_block_stop') {
                                $index = $event['index'] ?? 0;
                                $blockIdx = $blockIndices[$index] ?? null;

                                if ($blockIdx === null) {
                                    continue;
                                }

                                $block = $blocks[$blockIdx];

                                if ($block instanceof TextContent) {
                                    $stream->push(new TextEndEvent($blockIdx, $block->text, $output));
                                } elseif ($block instanceof ThinkingContent) {
                                    $stream->push(new ThinkingEndEvent($blockIdx, $block->thinking, $output));
                                } elseif ($block instanceof ToolCall) {
                                    unset($toolScratch[$blockIdx]);
                                    $stream->push(new ToolCallEndEvent($blockIdx, $block, $output));
                                }
                            } elseif ($eventType === 'message_delta') {
                                if (isset($event['delta']['stop_reason']) && is_string($event['delta']['stop_reason'])) {
                                    $output = $this->snapshot($model, $blocks, $output->usage, AnthropicShared::mapStopReason($event['delta']['stop_reason']), $output->responseId, $output->errorMessage);
                                }
                                if (isset($event['usage']) && is_array($event['usage'])) {
                                    $usage = new Usage(
                                        input: $event['usage']['input_tokens'] ?? $output->usage->input,
                                        output: $event['usage']['output_tokens'] ?? $output->usage->output,
                                        cacheRead: $event['usage']['cache_read_input_tokens'] ?? $output->usage->cacheRead,
                                        cacheWrite: $event['usage']['cache_creation_input_tokens'] ?? $output->usage->cacheWrite,
                                        totalTokens: 0,
                                        cost: $output->usage->cost,
                                    );
                                    $usage = new Usage(
                                        input: $usage->input,
                                        output: $usage->output,
                                        cacheRead: $usage->cacheRead,
                                        cacheWrite: $usage->cacheWrite,
                                        totalTokens: $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite,
                                        cost: $output->usage->cost,
                                    );
                                    Models::calculateCost($model, $usage);
                                    $output = $this->snapshot($model, $blocks, $usage, $output->stopReason, $output->responseId, $output->errorMessage);
                                }
                            }
                        }

                        if ($providerOptions->signal?->isCancelled()) {
                            throw new ProviderError('Request was aborted', 0, 'aborted');
                        }

                        if ($output->stopReason === StopReason::Aborted || $output->stopReason === StopReason::Error) {
                            throw new ProviderError('An unknown error occurred');
                        }

                        $stream->push(new DoneEvent($output->stopReason, $output));
                        $stream->end();

                        return null;
                    });
            },
            function (\Throwable $error) use ($stream, $model, $options): void {
                $output = $this->createOutput($model);
                $output = new AssistantMessage(
                    content: $output->content,
                    api: $output->api,
                    provider: $output->provider,
                    model: $output->model,
                    usage: $output->usage,
                    stopReason: $options?->signal?->isCancelled() ? StopReason::Aborted : StopReason::Error,
                    timestamp: $output->timestamp,
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
     *   output: AssistantMessage,
     *   blocks: array<int, TextContent|ThinkingContent|ToolCall>,
     *   blockIndices: array<int, int>,
     *   toolScratch: array<int, array{partialJson: string}>
     * }
     */
    private function initializeStreamState(AssistantMessageEventStream $stream, Model $model): array
    {
        $output = $this->createOutput($model);
        $stream->push(new StartEvent($output));

        return [
            'output' => $output,
            'blocks' => [],
            'blockIndices' => [],
            'toolScratch' => [],
        ];
    }

    /**
     * @param  array{
     *   output: AssistantMessage,
     *   blocks: array<int, TextContent|ThinkingContent|ToolCall>,
     *   blockIndices: array<int, int>,
     *   toolScratch: array<int, array{partialJson: string}>
     * }  $state
     * @param  array<string, mixed>  $event
     */
    private function processStreamEvent(array &$state, array $event, AssistantMessageEventStream $stream, Model $model): void
    {
        $eventType = $event['_eventType'] ?? null;
        unset($event['_eventType']);

        if ($eventType === 'message_start') {
            $state['output'] = $this->snapshot($model, $state['blocks'], $state['output']->usage, $state['output']->stopReason, $event['message']['id'] ?? null, $state['output']->errorMessage);
            if (isset($event['message']['usage']) && is_array($event['message']['usage'])) {
                $usage = new Usage(
                    input: $event['message']['usage']['input_tokens'] ?? 0,
                    output: $event['message']['usage']['output_tokens'] ?? 0,
                    cacheRead: $event['message']['usage']['cache_read_input_tokens'] ?? 0,
                    cacheWrite: $event['message']['usage']['cache_creation_input_tokens'] ?? 0,
                    totalTokens: 0,
                    cost: $state['output']->usage->cost,
                );
                $usage = new Usage(
                    input: $usage->input,
                    output: $usage->output,
                    cacheRead: $usage->cacheRead,
                    cacheWrite: $usage->cacheWrite,
                    totalTokens: $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite,
                    cost: $state['output']->usage->cost,
                );
                Models::calculateCost($model, $usage);
                $state['output'] = $this->snapshot($model, $state['blocks'], $usage, $state['output']->stopReason, $state['output']->responseId, $state['output']->errorMessage);
            }

            return;
        }

        if ($eventType === 'content_block_start') {
            $index = $event['index'] ?? 0;
            $contentBlock = $event['content_block'] ?? [];
            $blockType = $contentBlock['type'] ?? null;

            if ($blockType === 'text') {
                $state['blocks'][] = new TextContent('');
                $state['blockIndices'][$index] = count($state['blocks']) - 1;
                $stream->push(new TextStartEvent($state['blockIndices'][$index], $state['output']));
            } elseif ($blockType === 'thinking') {
                $state['blocks'][] = new ThinkingContent('', '');
                $state['blockIndices'][$index] = count($state['blocks']) - 1;
                $stream->push(new ThinkingStartEvent($state['blockIndices'][$index], $state['output']));
            } elseif ($blockType === 'redacted_thinking') {
                $state['blocks'][] = new ThinkingContent('[Reasoning redacted]', $contentBlock['data'] ?? '', true);
                $state['blockIndices'][$index] = count($state['blocks']) - 1;
                $stream->push(new ThinkingStartEvent($state['blockIndices'][$index], $state['output']));
            } elseif ($blockType === 'tool_use') {
                $toolIdx = count($state['blocks']);
                $state['blocks'][] = new ToolCall(
                    $contentBlock['id'] ?? '',
                    $contentBlock['name'] ?? '',
                    $contentBlock['input'] ?? [],
                );
                $state['toolScratch'][$toolIdx] = ['partialJson' => ''];
                $state['blockIndices'][$index] = $toolIdx;
                $stream->push(new ToolCallStartEvent($state['blockIndices'][$index], $state['output']));
            }

            return;
        }

        if ($eventType === 'content_block_delta') {
            $index = $event['index'] ?? 0;
            $delta = $event['delta'] ?? [];
            $blockIdx = $state['blockIndices'][$index] ?? null;

            if ($blockIdx === null) {
                return;
            }

            $block = $state['blocks'][$blockIdx];

            if ($delta['type'] === 'text_delta' && $block instanceof TextContent) {
                $state['blocks'][$blockIdx] = new TextContent($block->text.$delta['text']);
                $state['output'] = $this->snapshot($model, $state['blocks'], $state['output']->usage, $state['output']->stopReason, $state['output']->responseId, $state['output']->errorMessage);
                $stream->push(new TextDeltaEvent($blockIdx, $delta['text'], $state['output']));
            } elseif ($delta['type'] === 'thinking_delta' && $block instanceof ThinkingContent) {
                $state['blocks'][$blockIdx] = new ThinkingContent($block->thinking.$delta['thinking'], $block->thinkingSignature);
                $state['output'] = $this->snapshot($model, $state['blocks'], $state['output']->usage, $state['output']->stopReason, $state['output']->responseId, $state['output']->errorMessage);
                $stream->push(new ThinkingDeltaEvent($blockIdx, $delta['thinking'], $state['output']));
            } elseif ($delta['type'] === 'input_json_delta' && $block instanceof ToolCall) {
                $state['toolScratch'][$blockIdx]['partialJson'] = ($state['toolScratch'][$blockIdx]['partialJson'] ?? '').$delta['partial_json'];
                $state['blocks'][$blockIdx] = new ToolCall(
                    $block->id,
                    $block->name,
                    JsonParse::parseStreamingJson($state['toolScratch'][$blockIdx]['partialJson']),
                    $block->thoughtSignature,
                );
                $state['output'] = $this->snapshot($model, $state['blocks'], $state['output']->usage, $state['output']->stopReason, $state['output']->responseId, $state['output']->errorMessage);
                $stream->push(new ToolCallDeltaEvent($blockIdx, $delta['partial_json'], $state['output']));
            } elseif ($delta['type'] === 'signature_delta' && $block instanceof ThinkingContent) {
                $state['blocks'][$blockIdx] = new ThinkingContent($block->thinking, $block->thinkingSignature.$delta['signature']);
            }

            return;
        }

        if ($eventType === 'content_block_stop') {
            $index = $event['index'] ?? 0;
            $blockIdx = $state['blockIndices'][$index] ?? null;

            if ($blockIdx === null) {
                return;
            }

            $block = $state['blocks'][$blockIdx];

            if ($block instanceof TextContent) {
                $stream->push(new TextEndEvent($blockIdx, $block->text, $state['output']));
            } elseif ($block instanceof ThinkingContent) {
                $stream->push(new ThinkingEndEvent($blockIdx, $block->thinking, $state['output']));
            } elseif ($block instanceof ToolCall) {
                unset($state['toolScratch'][$blockIdx]);
                $stream->push(new ToolCallEndEvent($blockIdx, $block, $state['output']));
            }

            return;
        }

        if ($eventType === 'message_delta') {
            if (isset($event['delta']['stop_reason']) && is_string($event['delta']['stop_reason'])) {
                $state['output'] = $this->snapshot($model, $state['blocks'], $state['output']->usage, AnthropicShared::mapStopReason($event['delta']['stop_reason']), $state['output']->responseId, $state['output']->errorMessage);
            }
            if (isset($event['usage']) && is_array($event['usage'])) {
                $usage = new Usage(
                    input: $event['usage']['input_tokens'] ?? $state['output']->usage->input,
                    output: $event['usage']['output_tokens'] ?? $state['output']->usage->output,
                    cacheRead: $event['usage']['cache_read_input_tokens'] ?? $state['output']->usage->cacheRead,
                    cacheWrite: $event['usage']['cache_creation_input_tokens'] ?? $state['output']->usage->cacheWrite,
                    totalTokens: 0,
                    cost: $state['output']->usage->cost,
                );
                $usage = new Usage(
                    input: $usage->input,
                    output: $usage->output,
                    cacheRead: $usage->cacheRead,
                    cacheWrite: $usage->cacheWrite,
                    totalTokens: $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite,
                    cost: $state['output']->usage->cost,
                );
                Models::calculateCost($model, $usage);
                $state['output'] = $this->snapshot($model, $state['blocks'], $usage, $state['output']->stopReason, $state['output']->responseId, $state['output']->errorMessage);
            }
        }
    }

    /**
     * @param  array{
     *   output: AssistantMessage,
     *   blocks: array<int, TextContent|ThinkingContent|ToolCall>,
     *   blockIndices: array<int, int>,
     *   toolScratch: array<int, array{partialJson: string}>
     * }  $state
     */
    private function finalizeStreamState(array $state, AssistantMessageEventStream $stream, Model $model): AssistantMessage
    {
        return $this->snapshot(
            $model,
            $state['blocks'],
            $state['output']->usage,
            $state['output']->stopReason,
            $state['output']->responseId,
            $state['output']->errorMessage,
        );
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = SimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);

        if (! $options?->reasoning) {
            return $this->stream($model, $context, new AnthropicOptions(
                ...get_object_vars($base),
                thinkingEnabled: false,
            ));
        }

        if (self::supportsAdaptiveThinking($model->id)) {
            $effort = self::mapThinkingLevelToEffort($options->reasoning, $model->id);

            return $this->stream($model, $context, new AnthropicOptions(
                ...get_object_vars($base),
                thinkingEnabled: true,
                effort: $effort,
            ));
        }

        $adjusted = SimpleOptions::adjustMaxTokensForThinking(
            $base->maxTokens ?? 0,
            $model->maxTokens,
            $options->reasoning,
            $options->thinkingBudgets,
        );

        return $this->stream($model, $context, new AnthropicOptions(
            ...get_object_vars($base),
            maxTokens: $adjusted['maxTokens'],
            thinkingEnabled: true,
            thinkingBudgetTokens: $adjusted['thinkingBudget'],
        ));
    }

    private function buildParams(Model $model, Context $context, AnthropicOptions $options): array
    {
        $compat = AnthropicShared::getCompat($model);
        $cacheRetention = $options->cacheRetention;
        $cacheControl = null;
        if ($cacheRetention !== CacheRetention::None) {
            $ttl = $cacheRetention === CacheRetention::Long && $compat->supportsLongCacheRetention ? '1h' : null;
            $cacheControl = ['type' => 'ephemeral'];
            if ($ttl !== null) {
                $cacheControl['ttl'] = $ttl;
            }
        }

        $isOAuth = false;
        $apiKey = $options->apiKey ?: EnvApiKeys::getEnvApiKey($model->provider->value) ?: null;
        if ($apiKey !== null && $apiKey !== '' && str_contains($apiKey, 'sk-ant-oat')) {
            $isOAuth = true;
        }

        $params = [
            'model' => $model->id,
            'messages' => AnthropicShared::convertMessages($context->messages, $model, $cacheControl),
            'max_tokens' => $options->maxTokens ?? (int) ($model->maxTokens / 3),
            'stream' => true,
        ];

        if ($isOAuth) {
            $params['system'] = [
                [
                    'type' => 'text',
                    'text' => 'You are Claude Code, Anthropic\'s official CLI for Claude.',
                    ...($cacheControl ? ['cache_control' => $cacheControl] : []),
                ],
            ];
            if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
                $params['system'][] = [
                    'type' => 'text',
                    'text' => SanitizeUnicode::sanitizeSurrogates($context->systemPrompt),
                    ...($cacheControl ? ['cache_control' => $cacheControl] : []),
                ];
            }
        } elseif ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $params['system'] = [
                [
                    'type' => 'text',
                    'text' => SanitizeUnicode::sanitizeSurrogates($context->systemPrompt),
                    ...($cacheControl ? ['cache_control' => $cacheControl] : []),
                ],
            ];
        }

        if ($options->temperature !== null && $options->thinkingEnabled !== true) {
            $params['temperature'] = $options->temperature;
        }

        if ($context->tools !== []) {
            $params['tools'] = AnthropicShared::convertTools(
                $context->tools,
                $compat->supportsEagerToolInputStreaming ?? true,
                $cacheControl,
            );
        }

        if ($model->reasoning && $options->thinkingEnabled === true) {
            $display = $options->thinkingDisplay ?? 'summarized';
            if (self::supportsAdaptiveThinking($model->id)) {
                $params['thinking'] = ['type' => 'adaptive', 'display' => $display];
                if ($options->effort !== null) {
                    $params['output_config'] = ['effort' => $options->effort];
                }
            } else {
                $params['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => $options->thinkingBudgetTokens ?? 1024,
                    'display' => $display,
                ];
            }
        } elseif ($model->reasoning && $options->thinkingEnabled === false) {
            $params['thinking'] = ['type' => 'disabled'];
        }

        if ($options->metadata !== [] && isset($options->metadata['user_id']) && is_string($options->metadata['user_id'])) {
            $params['metadata'] = ['user_id' => $options->metadata['user_id']];
        }

        if ($options->toolChoice !== null) {
            if (is_string($options->toolChoice)) {
                $params['tool_choice'] = ['type' => $options->toolChoice];
            } else {
                $params['tool_choice'] = $options->toolChoice;
            }
        }

        return $params;
    }

    private static function supportsAdaptiveThinking(string $modelId): bool
    {
        return
            str_contains($modelId, 'opus-4-6') ||
            str_contains($modelId, 'opus-4.6') ||
            str_contains($modelId, 'opus-4-7') ||
            str_contains($modelId, 'opus-4.7') ||
            str_contains($modelId, 'sonnet-4-6') ||
            str_contains($modelId, 'sonnet-4.6');
    }

    private static function mapThinkingLevelToEffort(?ThinkingLevel $level, string $modelId): string
    {
        return match ($level) {
            ThinkingLevel::Minimal, ThinkingLevel::Low => 'low',
            ThinkingLevel::Medium => 'medium',
            ThinkingLevel::High => 'high',
            ThinkingLevel::Xhigh => (
                str_contains($modelId, 'opus-4-6') || str_contains($modelId, 'opus-4.6')
                    ? 'max'
                    : (str_contains($modelId, 'opus-4-7') || str_contains($modelId, 'opus-4.7')
                        ? 'xhigh'
                        : 'high')
            ),
            default => 'high',
        };
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

    private function snapshot(
        Model $model,
        array $content,
        Usage $usage,
        StopReason $stopReason,
        ?string $responseId,
        ?string $errorMessage,
    ): AssistantMessage {
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

    private static function mapToProviderOptions(?StreamOptions $options): AnthropicOptions
    {
        return new AnthropicOptions(
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
