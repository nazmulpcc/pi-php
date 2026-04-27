<?php

declare(strict_types=1);

namespace Pi\AI\Mistral;

use Closure;
use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\CacheRetention;
use Pi\AI\Content\ImageContent;
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
use Pi\AI\Message\Message;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\Schema\Schema;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Support\JsonParse;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\SimpleOptions;
use Pi\AI\Support\TransformMessages;
use Pi\AI\Tool;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Transport\ProviderError;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

final readonly class MistralProvider implements ApiProviderInterface
{
    /**
     * @param  null|callable(Model, Context, MistralOptions, array<string, mixed>): iterable<mixed>|array{events: iterable<mixed>, status?: int, headers?: array<string, string>}  $transport
     */
    public function __construct(
        private ?Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::MISTRAL_CONVERSATIONS);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof MistralOptions
            ? $options
            : self::mapToProviderOptions($options);

        try {
            $params = $this->buildParams($model, $context, $providerOptions);
            $nextParams = $providerOptions->onPayload?->__invoke($params, $model);
            if (is_array($nextParams)) {
                $params = $nextParams;
            }

            $status = 200;
            $headers = [];
            $shouldInvokeOnResponse = false;

            if ($this->transport !== null) {
                $result = ($this->transport)($model, $context, $providerOptions, $params);
                $shouldInvokeOnResponse = true;
                if (is_array($result) && array_key_exists('events', $result)) {
                    $events = $result['events'];
                    if (isset($result['status']) && is_int($result['status'])) {
                        $status = $result['status'];
                    }
                    if (isset($result['headers']) && is_array($result['headers'])) {
                        $headers = $result['headers'];
                    }
                } else {
                    $events = $result;
                }
            } else {
                $apiKey = $providerOptions->apiKey ?: EnvApiKeys::getEnvApiKey($model->provider->value) ?: null;
                if ($apiKey === null || $apiKey === '') {
                    throw new \RuntimeException(sprintf('No API key for provider: %s', $model->provider->value));
                }

                $url = rtrim($model->baseUrl, '/').'/chat/completions';
                $headers = $this->buildHeaders($model, $providerOptions);

                $onResponse = $providerOptions->onResponse !== null
                    ? static function (array $response) use ($providerOptions, $model): void {
                        $providerOptions->onResponse->__invoke([
                            'status' => $response['status'],
                            'headers' => $response['headers'],
                        ], $model);
                    }
                : null;

                $transport = new HttpTransport(
                    signal: $providerOptions->signal,
                    timeoutMs: $providerOptions->timeoutMs,
                    maxRetries: $providerOptions->maxRetries,
                    maxRetryDelayMs: $providerOptions->maxRetryDelayMs,
                );

                $events = $transport->stream('POST', $url, [
                    'headers' => $headers,
                    'body' => $params,
                    'apiKey' => $apiKey,
                    'onResponse' => $onResponse,
                ]);
            }

            $output = $this->createOutput($model);
            $stream->push(new StartEvent($output));

            $output = $this->consumeEvents($model, $output, $stream, $events);

            if ($shouldInvokeOnResponse && $providerOptions->onResponse !== null) {
                $providerOptions->onResponse->__invoke([
                    'status' => $status,
                    'headers' => $headers,
                ], $model);
            }

            if ($providerOptions->signal?->isCancelled()) {
                throw new ProviderError('Request was aborted', 0, 'aborted');
            }

            if ($output->stopReason === StopReason::Aborted || $output->stopReason === StopReason::Error) {
                throw new \RuntimeException($output->errorMessage ?? 'An unknown error occurred');
            }

            $stream->push(new DoneEvent($output->stopReason, $output));
            $stream->end();
        } catch (\Throwable $error) {
            $output = $this->createOutput($model);
            $output = new AssistantMessage(
                content: $output->content,
                api: $output->api,
                provider: $output->provider,
                model: $output->model,
                usage: $output->usage,
                stopReason: $this->isAbortError($error, $providerOptions) ? StopReason::Aborted : StopReason::Error,
                timestamp: $output->timestamp,
                errorMessage: $this->formatMistralError($error),
            );
            $stream->push(new ErrorEvent($output->stopReason, $output));
            $stream->end($output);
        }

        return $stream;
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = SimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);
        $reasoningEffort = null;
        $promptMode = null;

        if ($model->reasoning && $options?->reasoning !== null) {
            if ($this->usesReasoningEffort($model)) {
                $reasoningEffort = 'high';
            } else {
                $promptMode = 'reasoning';
            }
        }

        return $this->stream($model, $context, new MistralOptions(
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
            promptMode: $promptMode,
            reasoningEffort: $reasoningEffort,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(Model $model, Context $context, MistralOptions $options): array
    {
        $normalizeToolCallId = $this->createToolCallIdNormalizer();
        $messages = TransformMessages::transformMessages(
            $context->messages,
            $model,
            static function (string $id, Model $model, AssistantMessage $source) use ($normalizeToolCallId): string {
                if ($model->id === '' && $source->model === '') {
                    return $normalizeToolCallId($id);
                }

                return $normalizeToolCallId($id);
            },
        );

        $params = [
            'model' => $model->id,
            'stream' => true,
            'messages' => $this->toChatMessages($messages, in_array('image', $model->input, true)),
        ];

        if ($context->tools !== []) {
            $params['tools'] = $this->toFunctionTools($context->tools);
        }

        if ($options->temperature !== null) {
            $params['temperature'] = $options->temperature;
        }

        if ($options->maxTokens !== null) {
            $params['max_tokens'] = $options->maxTokens;
        }

        if ($options->toolChoice !== null) {
            $params['tool_choice'] = $this->mapToolChoice($options->toolChoice);
        }

        if ($options->promptMode !== null) {
            $params['prompt_mode'] = $options->promptMode;
        }

        if ($options->reasoningEffort !== null) {
            $params['reasoning_effort'] = $options->reasoningEffort;
        }

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            array_unshift($params['messages'], [
                'role' => 'system',
                'content' => SanitizeUnicode::sanitizeSurrogates($context->systemPrompt),
            ]);
        }

        return $params;
    }

    /**
     * @param  iterable<mixed>  $events
     */
    private function consumeEvents(Model $model, AssistantMessage $output, AssistantMessageEventStream $stream, iterable $events): AssistantMessage
    {
        $blocks = [];
        $currentBlock = null;
        $toolBlocksByIndex = [];
        $toolBlocksById = [];
        $toolScratch = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $chunk = isset($event['data']) && is_array($event['data']) ? $event['data'] : $event;
            $eventType = $event['type'] ?? $chunk['type'] ?? $event['_eventType'] ?? $chunk['_eventType'] ?? null;

            if (is_array($chunk['usage'] ?? null)) {
                $output = $this->updateUsage($model, $blocks, $output, $chunk['usage']);
            }

            $responseId = $chunk['id'] ?? $event['id'] ?? null;
            if (is_string($responseId) && $responseId !== '') {
                $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $responseId, $output->errorMessage);
            }

            if (is_array($chunk['choices'] ?? null)) {
                $choice = $chunk['choices'][0] ?? null;
                if (is_array($choice)) {
                    $finishReason = $choice['finishReason'] ?? $choice['finish_reason'] ?? null;
                    if (is_string($finishReason)) {
                        $output = $this->snapshot(
                            $model,
                            $blocks,
                            $output->usage,
                            $this->mapChatStopReason($finishReason),
                            $output->responseId,
                            $output->errorMessage,
                        );
                    }

                    if (isset($choice['delta']) && is_array($choice['delta'])) {
                        $output = $this->processDelta(
                            model: $model,
                            delta: $choice['delta'],
                            output: $output,
                            stream: $stream,
                            blocks: $blocks,
                            currentBlock: $currentBlock,
                            toolBlocksByIndex: $toolBlocksByIndex,
                            toolBlocksById: $toolBlocksById,
                            toolScratch: $toolScratch,
                        );
                    }
                }
            }

            if ($eventType === 'text_delta') {
                $delta = (string) ($event['delta'] ?? $chunk['delta'] ?? $event['text'] ?? $chunk['text'] ?? $event['content'] ?? $chunk['content'] ?? '');
                if ($delta !== '') {
                    $output = $this->appendTextDelta($model, $delta, $output, $stream, $blocks, $currentBlock);
                }

                continue;
            }

            if ($eventType === 'thinking_delta') {
                $delta = (string) ($event['delta'] ?? $chunk['delta'] ?? $event['thinking'] ?? $chunk['thinking'] ?? '');
                if ($delta !== '') {
                    $output = $this->appendThinkingDelta($model, $delta, $output, $stream, $blocks, $currentBlock);
                }

                continue;
            }

            if ($eventType === 'toolcall_delta') {
                $toolCall = is_array($event['toolCall'] ?? null) ? $event['toolCall'] : (is_array($chunk['toolCall'] ?? null) ? $chunk['toolCall'] : $event);
                $output = $this->appendToolCallDelta(
                    model: $model,
                    toolCall: $toolCall,
                    output: $output,
                    stream: $stream,
                    blocks: $blocks,
                    currentBlock: $currentBlock,
                    toolBlocksByIndex: $toolBlocksByIndex,
                    toolBlocksById: $toolBlocksById,
                    toolScratch: $toolScratch,
                );

                continue;
            }

            if ($eventType === 'done') {
                $reason = $event['reason'] ?? $chunk['reason'] ?? null;
                if (is_string($reason)) {
                    $output = $this->snapshot($model, $blocks, $output->usage, $this->mapChatStopReason($reason), $output->responseId, $output->errorMessage);
                }

                continue;
            }

            if ($eventType === 'error') {
                $message = (string) ($event['message'] ?? $chunk['message'] ?? 'Mistral API error');
                $output = $this->snapshot($model, $blocks, $output->usage, StopReason::Error, $output->responseId, $message);
                throw new \RuntimeException($message);
            }

            if ($eventType === 'content_delta' || $eventType === 'reasoning_delta') {
                $delta = (string) ($event['delta'] ?? $chunk['delta'] ?? '');
                if ($delta !== '') {
                    $output = $this->appendTextDelta($model, $delta, $output, $stream, $blocks, $currentBlock);
                }
            }
        }

        $output = $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);

        foreach ($blocks as $index => $block) {
            if (! $block instanceof ToolCall) {
                continue;
            }

            if (isset($toolScratch[$index]) && is_string($toolScratch[$index])) {
                $blocks[$index] = new ToolCall(
                    $block->id,
                    $block->name,
                    JsonParse::parseStreamingJson($toolScratch[$index]),
                    $block->thoughtSignature,
                );
                $block = $blocks[$index];
            }

            $stream->push(new ToolCallEndEvent($index, $block, $output));
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $delta
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     * @param  array<int, int>  $toolBlocksByIndex
     * @param  array<string, int>  $toolBlocksById
     * @param  array<int, string>  $toolScratch
     */
    private function processDelta(
        Model $model,
        array $delta,
        AssistantMessage $output,
        AssistantMessageEventStream $stream,
        array &$blocks,
        TextContent|ThinkingContent|ToolCall|null &$currentBlock,
        array &$toolBlocksByIndex,
        array &$toolBlocksById,
        array &$toolScratch,
    ): AssistantMessage {
        if (isset($delta['content'])) {
            $items = is_string($delta['content']) ? [$delta['content']] : (is_array($delta['content']) ? $delta['content'] : []);
            foreach ($items as $item) {
                if (is_string($item)) {
                    $output = $this->appendTextDelta($model, $item, $output, $stream, $blocks, $currentBlock);

                    continue;
                }

                if (! is_array($item)) {
                    continue;
                }

                if (($item['type'] ?? null) === 'thinking') {
                    $deltaText = '';
                    if (isset($item['thinking']) && is_array($item['thinking'])) {
                        foreach ($item['thinking'] as $part) {
                            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                                $deltaText .= $part['text'];
                            } elseif (is_string($part)) {
                                $deltaText .= $part;
                            }
                        }
                    }

                    if ($deltaText !== '') {
                        $output = $this->appendThinkingDelta($model, $deltaText, $output, $stream, $blocks, $currentBlock);
                    }

                    continue;
                }

                if (($item['type'] ?? null) === 'text' && isset($item['text']) && is_string($item['text'])) {
                    $output = $this->appendTextDelta($model, $item['text'], $output, $stream, $blocks, $currentBlock);
                }
            }
        }

        $toolCalls = $delta['tool_calls'] ?? $delta['toolCalls'] ?? null;
        if (is_array($toolCalls)) {
            foreach ($toolCalls as $toolCall) {
                if (is_array($toolCall)) {
                    $output = $this->appendToolCallDelta(
                        model: $model,
                        toolCall: $toolCall,
                        output: $output,
                        stream: $stream,
                        blocks: $blocks,
                        currentBlock: $currentBlock,
                        toolBlocksByIndex: $toolBlocksByIndex,
                        toolBlocksById: $toolBlocksById,
                        toolScratch: $toolScratch,
                    );
                }
            }
        }

        if (isset($delta['reasoning_details']) && is_array($delta['reasoning_details'])) {
            foreach ($delta['reasoning_details'] as $detail) {
                if (! is_array($detail) || ($detail['type'] ?? null) !== 'reasoning.encrypted') {
                    continue;
                }

                $detailId = isset($detail['id']) && is_string($detail['id']) ? $detail['id'] : null;
                if ($detailId === null) {
                    continue;
                }

                foreach ($blocks as $idx => $block) {
                    if ($block instanceof ToolCall && $block->id === $detailId) {
                        $blocks[$idx] = new ToolCall(
                            $block->id,
                            $block->name,
                            $block->arguments,
                            json_encode($detail, JSON_THROW_ON_ERROR),
                        );
                    }
                }
            }
        }

        return $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     */
    private function appendTextDelta(
        Model $model,
        string $delta,
        AssistantMessage $output,
        AssistantMessageEventStream $stream,
        array &$blocks,
        TextContent|ThinkingContent|ToolCall|null &$currentBlock,
    ): AssistantMessage {
        $delta = SanitizeUnicode::sanitizeSurrogates($delta);
        if ($delta === '') {
            return $output;
        }

        if (! $currentBlock instanceof TextContent) {
            $output = $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);
            $currentBlock = new TextContent('');
            $blocks[] = $currentBlock;
            $stream->push(new TextStartEvent(array_key_last($blocks), $output));
        }

        $index = array_key_last($blocks);
        $currentBlock = new TextContent($currentBlock->text.$delta);
        $blocks[$index] = $currentBlock;
        $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
        $stream->push(new TextDeltaEvent($index, $delta, $output));

        return $output;
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     */
    private function appendThinkingDelta(
        Model $model,
        string $delta,
        AssistantMessage $output,
        AssistantMessageEventStream $stream,
        array &$blocks,
        TextContent|ThinkingContent|ToolCall|null &$currentBlock,
    ): AssistantMessage {
        $delta = SanitizeUnicode::sanitizeSurrogates($delta);
        if ($delta === '') {
            return $output;
        }

        if (! $currentBlock instanceof ThinkingContent) {
            $output = $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);
            $currentBlock = new ThinkingContent('');
            $blocks[] = $currentBlock;
            $stream->push(new ThinkingStartEvent(array_key_last($blocks), $output));
        }

        $index = array_key_last($blocks);
        $currentBlock = new ThinkingContent($currentBlock->thinking.$delta, $currentBlock->thinkingSignature, $currentBlock->redacted);
        $blocks[$index] = $currentBlock;
        $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
        $stream->push(new ThinkingDeltaEvent($index, $delta, $output));

        return $output;
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     * @param  array<int, int>  $toolBlocksByIndex
     * @param  array<string, int>  $toolBlocksById
     * @param  array<int, string>  $toolScratch
     */
    private function appendToolCallDelta(
        Model $model,
        array $toolCall,
        AssistantMessage $output,
        AssistantMessageEventStream $stream,
        array &$blocks,
        TextContent|ThinkingContent|ToolCall|null &$currentBlock,
        array &$toolBlocksByIndex,
        array &$toolBlocksById,
        array &$toolScratch,
    ): AssistantMessage {
        $streamIndex = isset($toolCall['index']) && is_int($toolCall['index']) ? $toolCall['index'] : (isset($toolCall['index']) && is_numeric($toolCall['index']) ? (int) $toolCall['index'] : null);
        $toolCallId = isset($toolCall['id']) && is_string($toolCall['id']) && $toolCall['id'] !== '' && $toolCall['id'] !== 'null'
            ? $toolCall['id']
            : $this->deriveToolCallId('toolcall:'.(string) (count($blocks)));
        $toolCallName = isset($toolCall['function']['name']) && is_string($toolCall['function']['name']) ? $toolCall['function']['name'] : '';

        if ($currentBlock !== null) {
            $output = $this->finishCurrentBlock($currentBlock, $blocks, $stream, $output);
            $currentBlock = null;
        }

        $existingIndex = null;
        if ($streamIndex !== null && isset($toolBlocksByIndex[$streamIndex])) {
            $existingIndex = $toolBlocksByIndex[$streamIndex];
        } elseif (isset($toolBlocksById[$toolCallId])) {
            $existingIndex = $toolBlocksById[$toolCallId];
        }

        if ($existingIndex === null) {
            $existingIndex = count($blocks);
            $blocks[] = new ToolCall($toolCallId, $toolCallName, []);
            $toolScratch[$existingIndex] = '';
            $stream->push(new ToolCallStartEvent($existingIndex, $output));
        }

        $block = $blocks[$existingIndex] ?? null;
        if (! $block instanceof ToolCall) {
            $block = new ToolCall($toolCallId, $toolCallName, []);
            $blocks[$existingIndex] = $block;
        }

        if ($block->id === '' && $toolCallId !== '') {
            $block = new ToolCall($toolCallId, $block->name, $block->arguments, $block->thoughtSignature);
            $blocks[$existingIndex] = $block;
        }

        if ($block->name === '' && $toolCallName !== '') {
            $block = new ToolCall($block->id, $toolCallName, $block->arguments, $block->thoughtSignature);
            $blocks[$existingIndex] = $block;
        }

        if ($streamIndex !== null) {
            $toolBlocksByIndex[$streamIndex] = $existingIndex;
        }
        $toolBlocksById[$toolCallId] = $existingIndex;

        if (array_key_exists('arguments', $toolCall['function'] ?? [])) {
            $args = $toolCall['function']['arguments'];
            $argsDelta = is_string($args) ? $args : json_encode($args, JSON_THROW_ON_ERROR);
            $toolScratch[$existingIndex] = ($toolScratch[$existingIndex] ?? '').$argsDelta;
            $parsed = JsonParse::parseStreamingJson($toolScratch[$existingIndex]);
            $block = new ToolCall($block->id, $block->name, $parsed, $block->thoughtSignature);
            $blocks[$existingIndex] = $block;
            $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
            $stream->push(new ToolCallDeltaEvent($existingIndex, $argsDelta, $output));
        }

        return $output;
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     */
    private function finishCurrentBlock(
        TextContent|ThinkingContent|ToolCall|null $block,
        array &$blocks,
        AssistantMessageEventStream $stream,
        AssistantMessage $output,
    ): AssistantMessage {
        if ($block === null) {
            return $output;
        }

        $index = array_key_last($blocks);
        if ($index === null) {
            return $output;
        }

        if ($block instanceof TextContent) {
            $stream->push(new TextEndEvent($index, $block->text, $output));
        } elseif ($block instanceof ThinkingContent) {
            $stream->push(new ThinkingEndEvent($index, $block->thinking, $output));
        } elseif ($block instanceof ToolCall) {
            $stream->push(new ToolCallEndEvent($index, $block, $output));
        }

        return $output;
    }

    /**
     * @param  array<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function toChatMessages(array $messages, bool $supportsImages): array
    {
        $result = [];

        foreach ($messages as $msg) {
            if ($msg instanceof UserMessage) {
                if (is_string($msg->content)) {
                    $result[] = ['role' => 'user', 'content' => SanitizeUnicode::sanitizeSurrogates($msg->content)];

                    continue;
                }

                $content = [];
                $hadImages = false;
                foreach ($msg->content as $item) {
                    if ($item instanceof TextContent) {
                        $content[] = ['type' => 'text', 'text' => SanitizeUnicode::sanitizeSurrogates($item->text)];
                    } elseif ($item instanceof ImageContent) {
                        $hadImages = true;
                        if ($supportsImages) {
                            $content[] = ['type' => 'image_url', 'image_url' => ['url' => sprintf('data:%s;base64,%s', $item->mimeType, $item->data)]];
                        }
                    }
                }

                if ($content !== []) {
                    $result[] = ['role' => 'user', 'content' => $content];
                } elseif ($hadImages && ! $supportsImages) {
                    $result[] = ['role' => 'user', 'content' => '(image omitted: model does not support images)'];
                }

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                $contentParts = [];
                $toolCalls = [];

                foreach ($msg->content as $block) {
                    if ($block instanceof TextContent) {
                        if (trim($block->text) !== '') {
                            $contentParts[] = ['type' => 'text', 'text' => SanitizeUnicode::sanitizeSurrogates($block->text)];
                        }

                        continue;
                    }

                    if ($block instanceof ThinkingContent) {
                        if (trim($block->thinking) !== '') {
                            $contentParts[] = [
                                'type' => 'thinking',
                                'thinking' => [[
                                    'type' => 'text',
                                    'text' => SanitizeUnicode::sanitizeSurrogates($block->thinking),
                                ]],
                            ];
                        }

                        continue;
                    }

                    if ($block instanceof ToolCall) {
                        $toolCalls[] = [
                            'id' => $block->id,
                            'type' => 'function',
                            'function' => [
                                'name' => $block->name,
                                'arguments' => json_encode($block->arguments ?: [], JSON_THROW_ON_ERROR),
                            ],
                        ];
                    }
                }

                $assistantMessage = ['role' => 'assistant'];
                if ($contentParts !== []) {
                    $assistantMessage['content'] = $contentParts;
                }
                if ($toolCalls !== []) {
                    $assistantMessage['tool_calls'] = $toolCalls;
                }
                if ($contentParts !== [] || $toolCalls !== []) {
                    $result[] = $assistantMessage;
                }

                continue;
            }

            if ($msg instanceof ToolResultMessage) {
                $textResult = [];
                $hasImages = false;

                foreach ($msg->content as $part) {
                    if ($part instanceof TextContent) {
                        $textResult[] = SanitizeUnicode::sanitizeSurrogates($part->text);
                    } elseif ($part instanceof ImageContent) {
                        $hasImages = true;
                    }
                }

                $toolContent = [[
                    'type' => 'text',
                    'text' => $this->buildToolResultText(implode("\n", $textResult), $hasImages, $supportsImages, $msg->isError),
                ]];

                if ($supportsImages) {
                    foreach ($msg->content as $part) {
                        if ($part instanceof ImageContent) {
                            $toolContent[] = ['type' => 'image_url', 'image_url' => ['url' => sprintf('data:%s;base64,%s', $part->mimeType, $part->data)]];
                        }
                    }
                }

                $result[] = [
                    'role' => 'tool',
                    'tool_call_id' => $msg->toolCallId,
                    'name' => $msg->toolName,
                    'content' => $toolContent,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toFunctionTools(array $tools): array
    {
        return array_map(static function (Tool $tool): array {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters instanceof Schema ? $tool->parameters->toArray() : $tool->parameters,
                    'strict' => false,
                ],
            ];
        }, $tools);
    }

    private function buildToolResultText(string $text, bool $hasImages, bool $supportsImages, bool $isError): string
    {
        $trimmed = trim($text);
        $prefix = $isError ? '[tool error] ' : '';

        if ($trimmed !== '') {
            $suffix = $hasImages && ! $supportsImages ? "\n[tool image omitted: model does not support images]" : '';

            return $prefix.$trimmed.$suffix;
        }

        if ($hasImages) {
            if ($supportsImages) {
                return $isError ? '[tool error] (see attached image)' : '(see attached image)';
            }

            return $isError
                ? '[tool error] (image omitted: model does not support images)'
                : '(image omitted: model does not support images)';
        }

        return $isError ? '[tool error] (no tool output)' : '(no tool output)';
    }

    private function mapToolChoice(string|array $choice): array|string
    {
        if (is_array($choice)) {
            return $choice;
        }

        return match ($choice) {
            'auto', 'none', 'any', 'required' => $choice,
            default => ['type' => 'function', 'function' => ['name' => $choice]],
        };
    }

    private function mapChatStopReason(?string $reason): StopReason
    {
        if ($reason === null) {
            return StopReason::Stop;
        }

        return match ($reason) {
            'stop', 'end' => StopReason::Stop,
            'length', 'model_length' => StopReason::Length,
            'tool_calls', 'function_call' => StopReason::ToolUse,
            'error' => StopReason::Error,
            default => StopReason::Stop,
        };
    }

    private function updateUsage(Model $model, array $blocks, AssistantMessage $output, array $usage): AssistantMessage
    {
        $promptTokens = (int) ($usage['promptTokens'] ?? $usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completionTokens'] ?? $usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['totalTokens'] ?? $usage['total_tokens'] ?? ($promptTokens + $completionTokens));

        $nextUsage = new Usage(
            input: $promptTokens,
            output: $completionTokens,
            cacheRead: 0,
            cacheWrite: 0,
            totalTokens: $totalTokens,
            cost: new UsageCost,
        );
        $nextUsage = new Usage(
            input: $nextUsage->input,
            output: $nextUsage->output,
            cacheRead: $nextUsage->cacheRead,
            cacheWrite: $nextUsage->cacheWrite,
            totalTokens: $nextUsage->totalTokens,
            cost: Models::calculateCost($model, $nextUsage),
        );

        return $this->snapshot($model, $blocks, $nextUsage, $output->stopReason, $output->responseId, $output->errorMessage);
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     */
    private function snapshot(
        Model $model,
        array $blocks,
        Usage $usage,
        StopReason $stopReason,
        ?string $responseId,
        ?string $errorMessage,
    ): AssistantMessage {
        return new AssistantMessage(
            content: $blocks,
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

    private function buildHeaders(Model $model, MistralOptions $options): array
    {
        $headers = array_merge($model->headers, $options->headers);

        if ($options->sessionId !== null && $options->sessionId !== '' && ! isset($headers['x-affinity'])) {
            $headers['x-affinity'] = $options->sessionId;
        }

        return $headers;
    }

    private function isAbortError(\Throwable $error, MistralOptions $options): bool
    {
        if ($options->signal?->isCancelled()) {
            return true;
        }

        if ($error instanceof ProviderError && in_array($error->errorType, ['aborted', 'cancelled'], true)) {
            return true;
        }

        return false;
    }

    private function formatMistralError(\Throwable $error): string
    {
        if ($error instanceof ProviderError) {
            if ($error->status > 0) {
                $body = is_string($error->rawBody) ? trim($error->rawBody) : '';
                if ($body !== '') {
                    return sprintf('Mistral API error (%d): %s', $error->status, $this->truncateErrorText($body, 4000));
                }

                return sprintf('Mistral API error (%d): %s', $error->status, $error->getMessage());
            }

            return $error->getMessage();
        }

        return $error->getMessage();
    }

    private function truncateErrorText(string $text, int $maxChars): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return substr($text, 0, $maxChars).sprintf('... [truncated %d chars]', strlen($text) - $maxChars);
    }

    private function usesReasoningEffort(Model $model): bool
    {
        return in_array($model->id, ['mistral-small-2603', 'mistral-small-latest'], true);
    }

    /**
     * @return callable(string): string
     */
    private function createToolCallIdNormalizer(): callable
    {
        $idMap = [];
        $reverseMap = [];

        return static function (string $id) use (&$idMap, &$reverseMap): string {
            if (isset($idMap[$id])) {
                return $idMap[$id];
            }

            $normalized = preg_replace('/[^a-zA-Z0-9]/', '', $id) ?? '';
            $candidate = $normalized !== '' ? substr($normalized, 0, 9) : substr(hash('sha256', $id), 0, 9);

            $attempt = 0;
            while (true) {
                $current = $attempt === 0 ? $candidate : substr(hash('sha256', $id.':'.$attempt), 0, 9);
                if (! isset($reverseMap[$current]) || $reverseMap[$current] === $id) {
                    $idMap[$id] = $current;
                    $reverseMap[$current] = $id;

                    return $current;
                }

                $attempt++;
            }
        };
    }

    private function deriveToolCallId(string $seed): string
    {
        return substr(hash('sha256', $seed), 0, 9);
    }

    private static function mapToProviderOptions(?StreamOptions $options): MistralOptions
    {
        return new MistralOptions(
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
