<?php

declare(strict_types=1);

namespace Pi\AI\Anthropic;

use Pi\AI\Compat\AnthropicMessagesCompat;
use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Schema\Schema;
use Pi\AI\StopReason;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\TransformMessages;
use Pi\AI\Tool;

final class AnthropicShared
{
    public static function getCompat(Model $model): AnthropicMessagesCompat
    {
        $explicit = $model->compat;
        if (! is_array($explicit)) {
            return new AnthropicMessagesCompat;
        }

        return new AnthropicMessagesCompat(
            supportsEagerToolInputStreaming: $explicit['supportsEagerToolInputStreaming'] ?? null,
            supportsLongCacheRetention: $explicit['supportsLongCacheRetention'] ?? null,
        );
    }

    public static function normalizeToolCallId(string $id): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? $id, 0, 64);
    }

    public static function convertMessages(array $messages, Model $model, ?array $cacheControl = null): array
    {
        $params = [];
        $transformed = TransformMessages::transformMessages($messages, $model, self::normalizeToolCallId(...));

        foreach ($transformed as $msg) {
            if ($msg instanceof UserMessage) {
                if (is_string($msg->content)) {
                    if (trim($msg->content) !== '') {
                        $params[] = ['role' => 'user', 'content' => SanitizeUnicode::sanitizeSurrogates($msg->content)];
                    }
                } else {
                    $blocks = [];
                    foreach ($msg->content as $item) {
                        if ($item instanceof TextContent) {
                            $blocks[] = ['type' => 'text', 'text' => SanitizeUnicode::sanitizeSurrogates($item->text)];
                        } elseif ($item instanceof ImageContent) {
                            $blocks[] = [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => $item->mimeType,
                                    'data' => $item->data,
                                ],
                            ];
                        }
                    }
                    $filtered = array_values(array_filter($blocks, static function (array $b): bool {
                        if ($b['type'] === 'text') {
                            return trim($b['text']) !== '';
                        }

                        return true;
                    }));
                    if ($filtered === []) {
                        continue;
                    }
                    $params[] = ['role' => 'user', 'content' => $filtered];
                }
            } elseif ($msg instanceof AssistantMessage) {
                $blocks = [];
                foreach ($msg->content as $block) {
                    if ($block instanceof TextContent) {
                        if (trim($block->text) === '') {
                            continue;
                        }
                        $blocks[] = ['type' => 'text', 'text' => SanitizeUnicode::sanitizeSurrogates($block->text)];
                    } elseif ($block instanceof ThinkingContent) {
                        if ($block->redacted) {
                            $blocks[] = ['type' => 'redacted_thinking', 'data' => $block->thinkingSignature ?? ''];

                            continue;
                        }
                        if (trim($block->thinking) === '') {
                            continue;
                        }
                        if (! $block->thinkingSignature || trim($block->thinkingSignature) === '') {
                            $blocks[] = ['type' => 'text', 'text' => SanitizeUnicode::sanitizeSurrogates($block->thinking)];
                        } else {
                            $blocks[] = [
                                'type' => 'thinking',
                                'thinking' => SanitizeUnicode::sanitizeSurrogates($block->thinking),
                                'signature' => $block->thinkingSignature,
                            ];
                        }
                    } elseif ($block instanceof ToolCall) {
                        $blocks[] = [
                            'type' => 'tool_use',
                            'id' => $block->id,
                            'name' => $block->name,
                            'input' => $block->arguments,
                        ];
                    }
                }
                if ($blocks === []) {
                    continue;
                }
                $params[] = ['role' => 'assistant', 'content' => $blocks];
            } elseif ($msg instanceof ToolResultMessage) {
                $toolResults = [];
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $msg->toolCallId,
                    'content' => self::convertContentBlocks($msg->content),
                    'is_error' => $msg->isError,
                ];
                $params[] = ['role' => 'user', 'content' => $toolResults];
            }
        }

        if ($cacheControl !== null && $params !== []) {
            $lastMessage = $params[array_key_last($params)];
            if ($lastMessage['role'] === 'user') {
                if (is_array($lastMessage['content']) && ! isset($lastMessage['content']['type'])) {
                    $lastBlock = $lastMessage['content'][array_key_last($lastMessage['content'])];
                    if (is_array($lastBlock) && in_array($lastBlock['type'] ?? '', ['text', 'image', 'tool_result'], true)) {
                        $lastBlock['cache_control'] = $cacheControl;
                        $lastMessage['content'][array_key_last($lastMessage['content'])] = $lastBlock;
                    }
                } elseif (is_string($lastMessage['content'])) {
                    $lastMessage['content'] = [
                        ['type' => 'text', 'text' => $lastMessage['content'], 'cache_control' => $cacheControl],
                    ];
                }
                $params[array_key_last($params)] = $lastMessage;
            }
        }

        return $params;
    }

    public static function convertContentBlocks(array $content): array|string
    {
        $hasImages = false;
        foreach ($content as $c) {
            if ($c instanceof ImageContent) {
                $hasImages = true;
                break;
            }
        }

        if (! $hasImages) {
            return SanitizeUnicode::sanitizeSurrogates(implode("\n", array_map(
                static fn (TextContent $c): string => $c->text,
                array_values(array_filter($content, static fn ($c): bool => $c instanceof TextContent)),
            )));
        }

        $blocks = [];
        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $blocks[] = ['type' => 'text', 'text' => SanitizeUnicode::sanitizeSurrogates($block->text)];
            } elseif ($block instanceof ImageContent) {
                $blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $block->mimeType,
                        'data' => $block->data,
                    ],
                ];
            }
        }

        $hasText = false;
        foreach ($blocks as $b) {
            if ($b['type'] === 'text') {
                $hasText = true;
                break;
            }
        }
        if (! $hasText) {
            array_unshift($blocks, ['type' => 'text', 'text' => '(see attached image)']);
        }

        return $blocks;
    }

    public static function convertTools(array $tools, bool $supportsEagerToolInputStreaming, ?array $cacheControl = null): array
    {
        return array_values(array_map(static function (Tool $tool, int $index) use ($tools, $supportsEagerToolInputStreaming, $cacheControl): array {
            $parameters = $tool->parameters instanceof Schema ? $tool->parameters->toArray() : $tool->parameters;
            $result = [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $parameters['properties'] ?? [],
                    'required' => $parameters['required'] ?? [],
                ],
            ];
            if ($supportsEagerToolInputStreaming) {
                $result['eager_input_streaming'] = true;
            }
            if ($cacheControl !== null && $index === count($tools) - 1) {
                $result['cache_control'] = $cacheControl;
            }

            return $result;
        }, $tools, array_keys($tools)));
    }

    public static function mapStopReason(string $reason): StopReason
    {
        return match ($reason) {
            'end_turn' => StopReason::Stop,
            'max_tokens' => StopReason::Length,
            'tool_use' => StopReason::ToolUse,
            'refusal' => StopReason::Error,
            'pause_turn' => StopReason::Stop,
            'stop_sequence' => StopReason::Stop,
            'sensitive' => StopReason::Error,
            default => throw new \RuntimeException(sprintf('Unhandled stop reason: %s', $reason)),
        };
    }
}
