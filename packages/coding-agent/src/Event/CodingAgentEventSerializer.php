<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Event;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ThinkingContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\Event\AgentEndEvent;
use Pi\Agent\Event\AgentEvent;
use Pi\Agent\Event\AgentStartEvent;
use Pi\Agent\Event\MessageEndEvent;
use Pi\Agent\Event\MessageStartEvent;
use Pi\Agent\Event\MessageUpdateEvent;
use Pi\Agent\Event\ToolExecutionEndEvent;
use Pi\Agent\Event\ToolExecutionStartEvent;
use Pi\Agent\Event\ToolExecutionUpdateEvent;
use Pi\Agent\Event\TurnEndEvent;
use Pi\Agent\Event\TurnStartEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\CustomMessage;
use Pi\Agent\Message\ToolResultMessage;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\Tool\AgentToolResult;

final class CodingAgentEventSerializer
{
    public static function fromAgentEvent(AgentEvent $event, string $sessionId): CodingAgentEvent
    {
        $timestamp = (int) (microtime(true) * 1000);

        return new CodingAgentEvent(
            type: $event->getType()->value,
            sessionId: $sessionId,
            timestamp: $timestamp,
            data: match (true) {
                $event instanceof AgentStartEvent => [],
                $event instanceof AgentEndEvent => ['messages' => array_map([self::class, 'serializeMessage'], $event->messages)],
                $event instanceof TurnStartEvent => [],
                $event instanceof TurnEndEvent => [
                    'message' => self::serializeMessage($event->message),
                    'toolResults' => array_map([self::class, 'serializeMessage'], $event->toolResults),
                ],
                $event instanceof MessageStartEvent, $event instanceof MessageEndEvent => [
                    'message' => self::serializeMessage($event->message),
                ],
                $event instanceof MessageUpdateEvent => [
                    'message' => self::serializeMessage($event->message),
                    'rawEvent' => self::normalizeValue($event->rawEvent),
                ],
                $event instanceof ToolExecutionStartEvent => [
                    'toolCallId' => $event->toolCallId,
                    'toolName' => $event->toolName,
                    'args' => $event->args,
                ],
                $event instanceof ToolExecutionUpdateEvent => [
                    'toolCallId' => $event->toolCallId,
                    'toolName' => $event->toolName,
                    'args' => $event->args,
                    'partialResult' => self::serializeToolResult($event->partialResult),
                ],
                $event instanceof ToolExecutionEndEvent => [
                    'toolCallId' => $event->toolCallId,
                    'toolName' => $event->toolName,
                    'result' => self::serializeToolResult($event->result),
                    'isError' => $event->isError,
                ],
                default => [],
            },
        );
    }

    public static function serializeMessage(AgentMessage $message): array
    {
        return match (true) {
            $message instanceof UserMessage => [
                'role' => 'user',
                'timestamp' => $message->timestamp,
                'content' => array_map([self::class, 'serializeContent'], $message->content),
            ],
            $message instanceof AssistantMessage => [
                'role' => 'assistant',
                'timestamp' => $message->timestamp,
                'api' => $message->api,
                'provider' => $message->provider,
                'model' => $message->model,
                'stopReason' => $message->stopReason->value,
                'errorMessage' => $message->errorMessage,
                'content' => array_map([self::class, 'serializeContent'], $message->content),
            ],
            $message instanceof ToolResultMessage => [
                'role' => 'tool_result',
                'timestamp' => $message->timestamp,
                'toolCallId' => $message->toolCallId,
                'toolName' => $message->toolName,
                'isError' => $message->isError,
                'details' => self::normalizeValue($message->details),
                'content' => array_map([self::class, 'serializeContent'], $message->content),
            ],
            $message instanceof CustomMessage => [
                'role' => 'custom',
                'timestamp' => $message->timestamp,
                'customType' => $message->customType,
                'display' => $message->display,
                'details' => self::normalizeValue($message->details),
                'content' => array_map([self::class, 'serializeContent'], $message->content),
            ],
            default => throw new \RuntimeException('Unsupported message type: '.get_debug_type($message)),
        };
    }

    public static function serializeToolResult(mixed $result): mixed
    {
        if ($result instanceof AgentToolResult) {
            return [
                'content' => array_map([self::class, 'serializeContent'], $result->content),
                'details' => self::normalizeValue($result->details),
                'terminate' => $result->terminate,
            ];
        }

        return self::normalizeValue($result);
    }

    public static function serializeContent(object $content): array
    {
        return match (true) {
            $content instanceof TextContent => ['type' => 'text', 'text' => $content->text],
            $content instanceof ImageContent => ['type' => 'image', 'data' => $content->data, 'mimeType' => $content->mimeType],
            $content instanceof ThinkingContent => [
                'type' => 'thinking',
                'thinking' => $content->thinking,
                'thinkingSignature' => $content->thinkingSignature,
                'redacted' => $content->redacted,
            ],
            $content instanceof ToolCall => [
                'type' => 'tool_call',
                'id' => $content->id,
                'name' => $content->name,
                'arguments' => $content->arguments,
                'thoughtSignature' => $content->thoughtSignature,
            ],
            default => throw new \RuntimeException('Unsupported content type: '.get_debug_type($content)),
        };
    }

    public static function normalizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValue($item);
            }

            return $normalized;
        }

        if ($value instanceof \UnitEnum) {
            return $value->value ?? $value->name;
        }

        if ($value instanceof AgentMessage) {
            return self::serializeMessage($value);
        }

        if ($value instanceof AgentToolResult) {
            return self::serializeToolResult($value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return ['type' => get_debug_type($value)];
        }

        return $value;
    }
}
