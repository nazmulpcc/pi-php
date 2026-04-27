<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI;

use Pi\AI\AssistantMessageEventStream;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
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
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\Schema\Schema;
use Pi\AI\StopReason;
use Pi\AI\Support\JsonParse;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\TransformMessages;
use Pi\AI\Tool;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

final class OpenAIResponsesShared
{
    /**
     * @param  array<string>  $allowedToolCallProviders
     * @return array<int, array<string, mixed>>
     */
    public static function convertMessages(Model $model, Context $context, array $allowedToolCallProviders, array $options = []): array
    {
        $messages = [];
        $normalizeToolCallId = function (string $id, Model $targetModel, AssistantMessage $source) use ($allowedToolCallProviders): string {
            if (! in_array($targetModel->provider->value, $allowedToolCallProviders, true)) {
                return self::normalizeIdPart($id);
            }

            if (! str_contains($id, '|')) {
                return self::normalizeIdPart($id);
            }

            [$callId, $itemId] = explode('|', $id, 2);
            $normalizedCallId = self::normalizeIdPart($callId);
            $isForeignToolCall = ! $source->provider->equals($targetModel->provider) || ! $source->api->equals($targetModel->api);
            $normalizedItemId = $isForeignToolCall ? sprintf('fc_%s', self::shortHash($itemId)) : self::normalizeIdPart($itemId);

            if (! str_starts_with($normalizedItemId, 'fc_')) {
                $normalizedItemId = self::normalizeIdPart('fc_'.$normalizedItemId);
            }

            return sprintf('%s|%s', $normalizedCallId, $normalizedItemId);
        };

        $transformed = TransformMessages::transformMessages($context->messages, $model, $normalizeToolCallId);

        if (($options['includeSystemPrompt'] ?? true) && $context->systemPrompt !== null) {
            $messages[] = [
                'role' => $model->reasoning ? 'developer' : 'system',
                'content' => SanitizeUnicode::sanitizeSurrogates($context->systemPrompt),
            ];
        }

        $messageIndex = 0;
        foreach ($transformed as $message) {
            if ($message instanceof UserMessage) {
                if (is_string($message->content)) {
                    $messages[] = [
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => SanitizeUnicode::sanitizeSurrogates($message->content),
                        ]],
                    ];
                } else {
                    $messages[] = [
                        'role' => 'user',
                        'content' => array_map(static function ($item): array {
                            if ($item instanceof TextContent) {
                                return ['type' => 'input_text', 'text' => SanitizeUnicode::sanitizeSurrogates($item->text)];
                            }

                            return [
                                'type' => 'input_image',
                                'detail' => 'auto',
                                'image_url' => sprintf('data:%s;base64,%s', $item->mimeType, $item->data),
                            ];
                        }, $message->content),
                    ];
                }

                $messageIndex++;

                continue;
            }

            if ($message instanceof AssistantMessage) {
                foreach ($message->content as $block) {
                    if ($block instanceof ThinkingContent && $block->thinkingSignature !== null) {
                        $decoded = json_decode($block->thinkingSignature, true);
                        if (is_array($decoded)) {
                            $messages[] = $decoded;
                        }

                        continue;
                    }

                    if ($block instanceof TextContent) {
                        $messages[] = [
                            'type' => 'message',
                            'role' => 'assistant',
                            'content' => [[
                                'type' => 'output_text',
                                'text' => SanitizeUnicode::sanitizeSurrogates($block->text),
                                'annotations' => [],
                            ]],
                            'status' => 'completed',
                            'id' => sprintf('msg_%d', $messageIndex),
                        ];

                        continue;
                    }

                    if ($block instanceof ToolCall) {
                        [$callId, $itemId] = array_pad(explode('|', $block->id, 2), 2, null);
                        $messages[] = [
                            'type' => 'function_call',
                            'id' => $itemId,
                            'call_id' => $callId,
                            'name' => $block->name,
                            'arguments' => json_encode($block->arguments, JSON_THROW_ON_ERROR),
                        ];
                    }
                }

                $messageIndex++;

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                [$callId] = explode('|', $message->toolCallId, 2);
                $text = implode("\n", array_map(static fn (TextContent $content): string => $content->text, array_values(array_filter($message->content, static fn ($content): bool => $content instanceof TextContent))));
                $messages[] = [
                    'type' => 'function_call_output',
                    'call_id' => $callId,
                    'output' => SanitizeUnicode::sanitizeSurrogates($text !== '' ? $text : '(see attached image)'),
                ];
            }

            $messageIndex++;
        }

        return $messages;
    }

    /**
     * @param  array<Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    public static function convertTools(array $tools, array $options = []): array
    {
        $strict = $options['strict'] ?? false;

        return array_map(static function (Tool $tool) use ($strict): array {
            $parameters = $tool->parameters instanceof Schema ? $tool->parameters->toArray() : $tool->parameters;

            return [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $parameters,
                'strict' => $strict,
            ];
        }, $tools);
    }

    /**
     * @param  iterable<array<string, mixed>>  $events
     */
    public static function processStream(iterable $events, AssistantMessageEventStream $stream, Model $model): AssistantMessage
    {
        $state = self::initializeStreamState($stream, $model);

        foreach ($events as $event) {
            self::processStreamEvent($event, $stream, $model, $state);
        }

        $output = self::finalizeStreamState($stream, $model, $state);
        $stream->push(new DoneEvent($output->stopReason, $output));

        return $output;
    }

    /**
     * @return array{content: array<int, TextContent|ThinkingContent|ToolCall>, responseId: ?string, usage: Usage, stopReason: StopReason, scratch: array{partialJson: string}}
     */
    public static function initializeStreamState(AssistantMessageEventStream $stream, Model $model): array
    {
        $state = [
            'content' => [],
            'responseId' => null,
            'usage' => Usage::zero(),
            'stopReason' => StopReason::Stop,
            'scratch' => ['partialJson' => ''],
        ];

        $stream->push(new StartEvent(self::createOutput($model)));

        return $state;
    }

    /**
     * @param  array{content: array<int, TextContent|ThinkingContent|ToolCall>, responseId: ?string, usage: Usage, stopReason: StopReason, scratch: array{partialJson: string}}  $state
     */
    public static function processStreamEvent(array $event, AssistantMessageEventStream $stream, Model $model, array &$state): void
    {
        $content = &$state['content'];
        $responseId = &$state['responseId'];
        $usage = &$state['usage'];
        $stopReason = &$state['stopReason'];
        $scratch = &$state['scratch'];

        switch ($event['type'] ?? null) {
            case 'response.created':
                $responseId = $event['response']['id'] ?? null;
                break;

            case 'response.output_item.added':
                $item = $event['item'];
                if (($item['type'] ?? null) === 'reasoning') {
                    $content[] = new ThinkingContent('');
                    $stream->push(new ThinkingStartEvent(array_key_last($content), self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                } elseif (($item['type'] ?? null) === 'message') {
                    $content[] = new TextContent('');
                    $stream->push(new TextStartEvent(array_key_last($content), self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                } elseif (($item['type'] ?? null) === 'function_call') {
                    $content[] = new ToolCall(($item['call_id'] ?? '').'|'.($item['id'] ?? ''), $item['name'] ?? '', []);
                    $scratch['partialJson'] = (string) ($item['arguments'] ?? '');
                    $stream->push(new ToolCallStartEvent(array_key_last($content), self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                }
                break;

            case 'response.reasoning_summary_text.delta':
                $index = array_key_last($content);
                $current = $content[$index] ?? null;
                if ($current instanceof ThinkingContent) {
                    $content[$index] = new ThinkingContent($current->thinking.($event['delta'] ?? ''));
                    $stream->push(new ThinkingDeltaEvent($index, $event['delta'] ?? '', self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                }
                break;

            case 'response.output_text.delta':
            case 'response.refusal.delta':
                $index = array_key_last($content);
                $current = $content[$index] ?? null;
                if ($current instanceof TextContent) {
                    $content[$index] = new TextContent($current->text.($event['delta'] ?? ''));
                    $stream->push(new TextDeltaEvent($index, $event['delta'] ?? '', self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                }
                break;

            case 'response.function_call_arguments.delta':
                $index = array_key_last($content);
                $current = $content[$index] ?? null;
                if ($current instanceof ToolCall) {
                    $scratch['partialJson'] .= $event['delta'] ?? '';
                    $content[$index] = new ToolCall($current->id, $current->name, JsonParse::parseStreamingJson($scratch['partialJson']));
                    $stream->push(new ToolCallDeltaEvent($index, $event['delta'] ?? '', self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                }
                break;

            case 'response.function_call_arguments.done':
                $scratch['partialJson'] = $event['arguments'] ?? $scratch['partialJson'];
                break;

            case 'response.output_item.done':
                $item = $event['item'];
                $index = array_key_last($content);
                $current = $index !== null ? ($content[$index] ?? null) : null;
                if (($item['type'] ?? null) === 'reasoning' && $current instanceof ThinkingContent) {
                    $thinking = implode("\n\n", array_map(static fn (array $summary): string => $summary['text'], $item['summary'] ?? []));
                    $content[$index] = new ThinkingContent($thinking, json_encode($item, JSON_THROW_ON_ERROR));
                    $stream->push(new ThinkingEndEvent($index, $thinking, self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                } elseif (($item['type'] ?? null) === 'message' && $current instanceof TextContent) {
                    $text = implode('', array_map(static fn (array $part): string => $part['text'] ?? $part['refusal'] ?? '', $item['content'] ?? []));
                    $content[$index] = new TextContent($text, json_encode(['id' => $item['id'] ?? sprintf('msg_%d', $index)], JSON_THROW_ON_ERROR));
                    $stream->push(new TextEndEvent($index, $text, self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                } elseif (($item['type'] ?? null) === 'function_call' && $current instanceof ToolCall) {
                    $toolCall = new ToolCall(($item['call_id'] ?? '').'|'.($item['id'] ?? ''), $item['name'] ?? '', JsonParse::parseStreamingJson($scratch['partialJson'] ?: ($item['arguments'] ?? '{}')));
                    $content[$index] = $toolCall;
                    $stream->push(new ToolCallEndEvent($index, $toolCall, self::snapshot($model, $content, $usage, $stopReason, $responseId)));
                }
                break;

            case 'response.completed':
                $responseId = $event['response']['id'] ?? $responseId;
                $usageData = $event['response']['usage'] ?? [];
                $cachedTokens = $usageData['input_tokens_details']['cached_tokens'] ?? 0;
                $usage = new Usage(
                    input: ($usageData['input_tokens'] ?? 0) - $cachedTokens,
                    output: $usageData['output_tokens'] ?? 0,
                    cacheRead: $cachedTokens,
                    cacheWrite: 0,
                    totalTokens: $usageData['total_tokens'] ?? 0,
                    cost: new UsageCost,
                );
                $usage = new Usage(
                    input: $usage->input,
                    output: $usage->output,
                    cacheRead: $usage->cacheRead,
                    cacheWrite: $usage->cacheWrite,
                    totalTokens: $usage->totalTokens,
                    cost: Models::calculateCost($model, $usage),
                );
                $stopReason = self::mapStopReason($event['response']['status'] ?? null);
                if (array_filter($content, static fn ($block): bool => $block instanceof ToolCall) !== [] && $stopReason === StopReason::Stop) {
                    $stopReason = StopReason::ToolUse;
                }
                break;
        }
    }

    /**
     * @param  array{content: array<int, TextContent|ThinkingContent|ToolCall>, responseId: ?string, usage: Usage, stopReason: StopReason, scratch: array{partialJson: string}}  $state
     */
    public static function finalizeStreamState(AssistantMessageEventStream $stream, Model $model, array $state): AssistantMessage
    {
        return self::snapshot($model, $state['content'], $state['usage'], $state['stopReason'], $state['responseId']);
    }

    private static function createOutput(Model $model): AssistantMessage
    {
        return self::snapshot($model, [], Usage::zero(), StopReason::Stop, null);
    }

    /**
     * @param  array<TextContent|ThinkingContent|ToolCall>  $content
     */
    private static function snapshot(Model $model, array $content, Usage $usage, StopReason $stopReason, ?string $responseId): AssistantMessage
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
        );
    }

    private static function mapStopReason(?string $status): StopReason
    {
        return match ($status) {
            'incomplete' => StopReason::Length,
            'failed', 'cancelled' => StopReason::Error,
            default => StopReason::Stop,
        };
    }

    private static function normalizeIdPart(string $part): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $part) ?? $part;

        return rtrim(substr($sanitized, 0, 64), '_');
    }

    private static function shortHash(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}
