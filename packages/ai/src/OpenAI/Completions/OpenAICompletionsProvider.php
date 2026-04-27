<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI\Completions;

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
use Pi\AI\OpenAI\SimpleOptions;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Support\JsonParse;
use Pi\AI\Support\PromiseHelper;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Transport\ProviderError;
use Pi\AI\Usage;

final readonly class OpenAICompletionsProvider implements ApiProviderInterface
{
    /**
     * @param  null|callable(Model, Context, OpenAICompletionsOptions, array<string, mixed>): iterable<array<string, mixed>>  $transport
     */
    public function __construct(
        private ?\Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::OPENAI_COMPLETIONS);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof OpenAICompletionsOptions
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
                        $url = rtrim($model->baseUrl, '/').'/chat/completions';
                        $headers = array_merge($model->headers, $providerOptions->headers);

                        $transport = new HttpTransport(
                            signal: $providerOptions->signal,
                            timeoutMs: $providerOptions->timeoutMs,
                            maxRetries: $providerOptions->maxRetries,
                            maxRetryDelayMs: $providerOptions->maxRetryDelayMs,
                        );

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
                        ]);
                    })
                    ->then(function ($events) use ($model, $providerOptions, $stream) {
                        $output = $this->createOutput($model);
                        $stream->push(new StartEvent($output));

                        $currentBlock = null;
                        $blocks = [];
                        $scratch = ['partialArgs' => '', 'streamIndex' => null];
                        $responseId = null;
                        $usage = Usage::zero();
                        $stopReason = StopReason::Stop;
                        $errorMessage = null;

                        foreach ($events as $event) {
                            if (! is_array($event)) {
                                continue;
                            }

                            $responseId ??= $event['id'] ?? null;

                            if (isset($event['usage']) && is_array($event['usage'])) {
                                $usage = OpenAICompletionsShared::parseChunkUsage($event['usage'], $model);
                            }

                            $choice = isset($event['choices']) && is_array($event['choices']) ? ($event['choices'][0] ?? null) : null;
                            if (! is_array($choice)) {
                                continue;
                            }

                            if (! isset($event['usage']) && isset($choice['usage']) && is_array($choice['usage'])) {
                                $usage = OpenAICompletionsShared::parseChunkUsage($choice['usage'], $model);
                            }

                            if (isset($choice['finish_reason'])) {
                                $finishResult = OpenAICompletionsShared::mapStopReason($choice['finish_reason']);
                                $stopReason = $finishResult['stopReason'];
                                if (isset($finishResult['errorMessage'])) {
                                    $errorMessage = $finishResult['errorMessage'];
                                }
                            }

                            $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);

                            if (! isset($choice['delta']) || ! is_array($choice['delta'])) {
                                continue;
                            }

                            $delta = $choice['delta'];

                            if (isset($delta['content']) && $delta['content'] !== null && $delta['content'] !== '') {
                                if ($currentBlock === null || ! $currentBlock instanceof TextContent) {
                                    $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);
                                    $currentBlock = new TextContent('');
                                    $blocks[] = $currentBlock;
                                    $stream->push(new TextStartEvent(array_key_last($blocks), $output));
                                }
                                $currentBlock = new TextContent($currentBlock->text.$delta['content']);
                                $blocks[array_key_last($blocks)] = $currentBlock;
                                $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                                $stream->push(new TextDeltaEvent(array_key_last($blocks), $delta['content'], $output));
                            }

                            $reasoningFields = ['reasoning_content', 'reasoning', 'reasoning_text'];
                            $foundReasoningField = null;
                            foreach ($reasoningFields as $field) {
                                if (isset($delta[$field]) && $delta[$field] !== null && $delta[$field] !== '') {
                                    $foundReasoningField = $field;
                                    break;
                                }
                            }

                            if ($foundReasoningField !== null) {
                                if ($currentBlock === null || ! $currentBlock instanceof ThinkingContent) {
                                    $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);
                                    $currentBlock = new ThinkingContent('', $foundReasoningField);
                                    $blocks[] = $currentBlock;
                                    $stream->push(new ThinkingStartEvent(array_key_last($blocks), $output));
                                }
                                $currentBlock = new ThinkingContent($currentBlock->thinking.$delta[$foundReasoningField], $foundReasoningField);
                                $blocks[array_key_last($blocks)] = $currentBlock;
                                $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                                $stream->push(new ThinkingDeltaEvent(array_key_last($blocks), $delta[$foundReasoningField], $output));
                            }

                            if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                                foreach ($delta['tool_calls'] as $toolCall) {
                                    if (! is_array($toolCall)) {
                                        continue;
                                    }
                                    $streamIndex = isset($toolCall['index']) && is_int($toolCall['index']) ? $toolCall['index'] : null;
                                    $toolCallId = isset($toolCall['id']) && is_string($toolCall['id']) ? $toolCall['id'] : '';
                                    $toolCallName = isset($toolCall['function']['name']) && is_string($toolCall['function']['name'])
                                        ? $toolCall['function']['name']
                                        : '';

                                    $sameToolCall = $currentBlock instanceof ToolCall
                                        && (($streamIndex !== null && $scratch['streamIndex'] === $streamIndex)
                                            || ($streamIndex === null && $toolCallId !== '' && $currentBlock->id === $toolCallId));

                                    if (! $sameToolCall) {
                                        $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);
                                        $scratch = ['partialArgs' => '', 'streamIndex' => $streamIndex];
                                        $currentBlock = new ToolCall($toolCallId, $toolCallName, []);
                                        $blocks[] = $currentBlock;
                                        $stream->push(new ToolCallStartEvent(array_key_last($blocks), $output));
                                    }

                                    if ($currentBlock instanceof ToolCall) {
                                        if ($currentBlock->id === '' && $toolCallId !== '') {
                                            $currentBlock = new ToolCall($toolCallId, $currentBlock->name, $currentBlock->arguments, $currentBlock->thoughtSignature);
                                            $blocks[array_key_last($blocks)] = $currentBlock;
                                        }
                                        if ($currentBlock->name === '' && $toolCallName !== '') {
                                            $currentBlock = new ToolCall($currentBlock->id, $toolCallName, $currentBlock->arguments, $currentBlock->thoughtSignature);
                                            $blocks[array_key_last($blocks)] = $currentBlock;
                                        }
                                        if ($scratch['streamIndex'] === null && $streamIndex !== null) {
                                            $scratch['streamIndex'] = $streamIndex;
                                        }
                                        if (isset($toolCall['function']['arguments']) && is_string($toolCall['function']['arguments'])) {
                                            $scratch['partialArgs'] .= $toolCall['function']['arguments'];
                                            $currentBlock = new ToolCall(
                                                $currentBlock->id,
                                                $currentBlock->name,
                                                JsonParse::parseStreamingJson($scratch['partialArgs']),
                                                $currentBlock->thoughtSignature,
                                            );
                                            $blocks[array_key_last($blocks)] = $currentBlock;
                                            $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                                            $stream->push(new ToolCallDeltaEvent(array_key_last($blocks), $toolCall['function']['arguments'], $output));
                                        }
                                    }
                                }
                            }

                            if (isset($delta['reasoning_details']) && is_array($delta['reasoning_details'])) {
                                foreach ($delta['reasoning_details'] as $detail) {
                                    if (! is_array($detail) || ($detail['type'] ?? null) !== 'reasoning.encrypted') {
                                        continue;
                                    }
                                    $detailId = $detail['id'] ?? null;
                                    if ($detailId === null) {
                                        continue;
                                    }
                                    foreach ($blocks as $idx => $block) {
                                        if ($block instanceof ToolCall && $block->id === $detailId) {
                                            $blocks[$idx] = new ToolCall($block->id, $block->name, $block->arguments, json_encode($detail, JSON_THROW_ON_ERROR));
                                        }
                                    }
                                }
                            }
                        }

                        $output = $this->snapshot($model, $blocks, $usage, $stopReason, $responseId, $errorMessage);
                        $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);

                        if ($providerOptions->signal?->isCancelled()) {
                            throw new ProviderError('Request was aborted', 0, 'aborted');
                        }

                        if ($stopReason === StopReason::Aborted) {
                            throw new ProviderError('Request was aborted', 0, 'aborted');
                        }
                        if ($stopReason === StopReason::Error) {
                            throw new ProviderError($errorMessage ?: 'Provider returned an error stop reason');
                        }

                        $stream->push(new DoneEvent($stopReason, $output));
                        $stream->end();
                    });
            },
            function (\Throwable $error) use ($stream, $options, $model): void {
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

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = SimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);
        $reasoningEffort = Models::supportsXhigh($model) ? $options?->reasoning?->value : SimpleOptions::clampReasoning($options?->reasoning)?->value;

        return $this->stream($model, $context, new OpenAICompletionsOptions(
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
            reasoningEffort: $reasoningEffort,
        ));
    }

    private function buildParams(Model $model, Context $context, OpenAICompletionsOptions $options): array
    {
        $compat = OpenAICompletionsShared::getCompat($model);
        $cacheRetention = $options->cacheRetention;
        $messages = OpenAICompletionsShared::convertMessages($model, $context, $compat);

        $params = [
            'model' => $model->id,
            'messages' => $messages,
            'stream' => true,
        ];

        if (str_contains($model->baseUrl, 'api.openai.com') && $cacheRetention !== CacheRetention::None) {
            $params['prompt_cache_key'] = $options->sessionId;
        } elseif ($cacheRetention === CacheRetention::Long && $compat->supportsLongCacheRetention) {
            $params['prompt_cache_key'] = $options->sessionId;
            $params['prompt_cache_retention'] = '24h';
        }

        if ($compat->supportsUsageInStreaming !== false) {
            $params['stream_options'] = ['include_usage' => true];
        }

        if ($compat->supportsStore) {
            $params['store'] = false;
        }

        if ($options->maxTokens !== null) {
            if ($compat->maxTokensField === 'max_tokens') {
                $params['max_tokens'] = $options->maxTokens;
            } else {
                $params['max_completion_tokens'] = $options->maxTokens;
            }
        }

        if ($options->temperature !== null) {
            $params['temperature'] = $options->temperature;
        }

        if ($context->tools !== []) {
            $params['tools'] = OpenAICompletionsShared::convertTools($context->tools, $compat);
            if ($compat->zaiToolStream) {
                $params['tool_stream'] = true;
            }
        } elseif (OpenAICompletionsShared::hasToolHistory($context->messages)) {
            $params['tools'] = [];
        }

        if ($options->toolChoice !== null) {
            $params['tool_choice'] = $options->toolChoice;
        }

        if ($compat->thinkingFormat === 'zai' && $model->reasoning) {
            $params['enable_thinking'] = $options->reasoningEffort !== null;
        } elseif ($compat->thinkingFormat === 'qwen' && $model->reasoning) {
            $params['enable_thinking'] = $options->reasoningEffort !== null;
        } elseif ($compat->thinkingFormat === 'qwen-chat-template' && $model->reasoning) {
            $params['chat_template_kwargs'] = [
                'enable_thinking' => $options->reasoningEffort !== null,
                'preserve_thinking' => true,
            ];
        } elseif ($compat->thinkingFormat === 'deepseek' && $model->reasoning) {
            $params['thinking'] = ['type' => $options->reasoningEffort !== null ? 'enabled' : 'disabled'];
            if ($options->reasoningEffort !== null) {
                $params['reasoning_effort'] = self::mapReasoningEffort($options->reasoningEffort, $compat->reasoningEffortMap ?? []);
            }
        } elseif ($compat->thinkingFormat === 'openrouter' && $model->reasoning) {
            if ($options->reasoningEffort !== null) {
                $params['reasoning'] = ['effort' => self::mapReasoningEffort($options->reasoningEffort, $compat->reasoningEffortMap ?? [])];
            } else {
                $params['reasoning'] = ['effort' => 'none'];
            }
        } elseif ($options->reasoningEffort !== null && $model->reasoning && $compat->supportsReasoningEffort !== false) {
            $params['reasoning_effort'] = self::mapReasoningEffort($options->reasoningEffort, $compat->reasoningEffortMap ?? []);
        }

        if (str_contains($model->baseUrl, 'openrouter.ai') && $model->compat !== null && is_array($model->compat) && isset($model->compat['openRouterRouting'])) {
            $params['provider'] = $model->compat['openRouterRouting'];
        }

        if (str_contains($model->baseUrl, 'ai-gateway.vercel.sh') && $model->compat !== null && is_array($model->compat) && isset($model->compat['vercelGatewayRouting'])) {
            $routing = $model->compat['vercelGatewayRouting'];
            if (is_array($routing) && (isset($routing['only']) || isset($routing['order']))) {
                $gatewayOptions = [];
                if (isset($routing['only'])) {
                    $gatewayOptions['only'] = $routing['only'];
                }
                if (isset($routing['order'])) {
                    $gatewayOptions['order'] = $routing['order'];
                }
                $params['providerOptions'] = ['gateway' => $gatewayOptions];
            }
        }

        return $params;
    }

    private static function mapReasoningEffort(string $effort, array $map): string
    {
        return $map[$effort] ?? $effort;
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

    private function finishCurrentBlock(
        TextContent|ThinkingContent|ToolCall|null $block,
        array &$blocks,
        AssistantMessageEventStream $stream,
        AssistantMessage $output,
    ): void {
        if ($block === null) {
            return;
        }

        $index = array_key_last($blocks);
        if ($index === null) {
            return;
        }

        if ($block instanceof TextContent) {
            $stream->push(new TextEndEvent($index, $block->text, $output));
        } elseif ($block instanceof ThinkingContent) {
            $stream->push(new ThinkingEndEvent($index, $block->thinking, $output));
        } elseif ($block instanceof ToolCall) {
            $stream->push(new ToolCallEndEvent($index, $block, $output));
        }
    }

    private static function mapToProviderOptions(?StreamOptions $options): OpenAICompletionsOptions
    {
        return new OpenAICompletionsOptions(
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
