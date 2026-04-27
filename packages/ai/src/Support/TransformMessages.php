<?php

declare(strict_types=1);

namespace Pi\AI\Support;

use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\Message;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\StopReason;

final class TransformMessages
{
    private const NON_VISION_USER_IMAGE_PLACEHOLDER = '(image omitted: model does not support images)';

    private const NON_VISION_TOOL_IMAGE_PLACEHOLDER = '(tool image omitted: model does not support images)';

    /**
     * @param  array<Message>  $messages
     * @return array<Message>
     */
    public static function transformMessages(array $messages, Model $model, ?callable $normalizeToolCallId = null): array
    {
        $toolCallIdMap = [];
        $imageAwareMessages = self::downgradeUnsupportedImages($messages, $model);

        $transformed = array_map(function (Message $message) use ($model, $normalizeToolCallId, &$toolCallIdMap): Message {
            if ($message instanceof UserMessage) {
                return $message;
            }

            if ($message instanceof ToolResultMessage) {
                $normalizedId = $toolCallIdMap[$message->toolCallId] ?? $message->toolCallId;

                return $normalizedId === $message->toolCallId
                    ? $message
                    : new ToolResultMessage(
                        toolCallId: $normalizedId,
                        toolName: $message->toolName,
                        content: $message->content,
                        isError: $message->isError,
                        timestamp: $message->timestamp,
                        details: $message->details,
                    );
            }

            if ($message instanceof AssistantMessage) {
                $isSameModel = $message->provider->equals($model->provider)
                    && $message->api->equals($model->api)
                    && $message->model === $model->id;

                $content = [];
                foreach ($message->content as $block) {
                    if ($block instanceof ThinkingContent) {
                        if ($block->redacted) {
                            if ($isSameModel) {
                                $content[] = $block;
                            }

                            continue;
                        }

                        if ($isSameModel && $block->thinkingSignature !== null) {
                            $content[] = $block;

                            continue;
                        }

                        if (trim($block->thinking) === '') {
                            continue;
                        }

                        $content[] = $isSameModel ? $block : new TextContent($block->thinking);

                        continue;
                    }

                    if ($block instanceof TextContent) {
                        $content[] = $isSameModel ? $block : new TextContent($block->text);

                        continue;
                    }

                    if ($block instanceof ToolCall) {
                        $normalizedToolCall = $block;

                        if (! $isSameModel && $block->thoughtSignature !== null) {
                            $normalizedToolCall = new ToolCall(
                                id: $block->id,
                                name: $block->name,
                                arguments: $block->arguments,
                            );
                        }

                        if (! $isSameModel && $normalizeToolCallId !== null) {
                            $normalizedId = $normalizeToolCallId($block->id, $model, $message);
                            if ($normalizedId !== $block->id) {
                                $toolCallIdMap[$block->id] = $normalizedId;
                                $normalizedToolCall = new ToolCall(
                                    id: $normalizedId,
                                    name: $normalizedToolCall->name,
                                    arguments: $normalizedToolCall->arguments,
                                    thoughtSignature: $normalizedToolCall->thoughtSignature,
                                );
                            }
                        }

                        $content[] = $normalizedToolCall;
                    }
                }

                return new AssistantMessage(
                    content: $content,
                    api: $message->api,
                    provider: $message->provider,
                    model: $message->model,
                    usage: $message->usage,
                    stopReason: $message->stopReason,
                    timestamp: $message->timestamp,
                    responseId: $message->responseId,
                    errorMessage: $message->errorMessage,
                );
            }

            return $message;
        }, $imageAwareMessages);

        $result = [];
        $pendingToolCalls = [];
        $existingToolResultIds = [];

        $insertSyntheticToolResults = function () use (&$result, &$pendingToolCalls, &$existingToolResultIds): void {
            foreach ($pendingToolCalls as $toolCall) {
                if (! in_array($toolCall->id, $existingToolResultIds, true)) {
                    $result[] = new ToolResultMessage(
                        toolCallId: $toolCall->id,
                        toolName: $toolCall->name,
                        content: [new TextContent('No result provided')],
                        isError: true,
                        timestamp: 0,
                    );
                }
            }

            $pendingToolCalls = [];
            $existingToolResultIds = [];
        };

        foreach ($transformed as $message) {
            if ($message instanceof AssistantMessage) {
                $insertSyntheticToolResults();

                if (in_array($message->stopReason, [StopReason::Error, StopReason::Aborted], true)) {
                    continue;
                }

                $toolCalls = array_values(array_filter($message->content, static fn (mixed $block): bool => $block instanceof ToolCall));
                if ($toolCalls !== []) {
                    $pendingToolCalls = $toolCalls;
                    $existingToolResultIds = [];
                }

                $result[] = $message;

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $existingToolResultIds[] = $message->toolCallId;
                $result[] = $message;

                continue;
            }

            if ($message instanceof UserMessage) {
                $insertSyntheticToolResults();
            }

            $result[] = $message;
        }

        $insertSyntheticToolResults();

        return $result;
    }

    /**
     * @param  array<Message>  $messages
     * @return array<Message>
     */
    private static function downgradeUnsupportedImages(array $messages, Model $model): array
    {
        if (in_array('image', $model->input, true)) {
            return $messages;
        }

        return array_map(static function (Message $message): Message {
            if ($message instanceof UserMessage && is_array($message->content)) {
                return new UserMessage(
                    content: self::replaceImagesWithPlaceholder($message->content, self::NON_VISION_USER_IMAGE_PLACEHOLDER),
                    timestamp: $message->timestamp,
                );
            }

            if ($message instanceof ToolResultMessage) {
                return new ToolResultMessage(
                    toolCallId: $message->toolCallId,
                    toolName: $message->toolName,
                    content: self::replaceImagesWithPlaceholder($message->content, self::NON_VISION_TOOL_IMAGE_PLACEHOLDER),
                    isError: $message->isError,
                    timestamp: $message->timestamp,
                    details: $message->details,
                );
            }

            return $message;
        }, $messages);
    }

    /**
     * @param  array<TextContent|ImageContent>  $content
     * @return array<TextContent>
     */
    private static function replaceImagesWithPlaceholder(array $content, string $placeholder): array
    {
        $result = [];
        $previousWasPlaceholder = false;

        foreach ($content as $block) {
            if ($block instanceof ImageContent) {
                if (! $previousWasPlaceholder) {
                    $result[] = new TextContent($placeholder);
                }

                $previousWasPlaceholder = true;

                continue;
            }

            $result[] = $block;
            $previousWasPlaceholder = $block->text === $placeholder;
        }

        return $result;
    }
}
