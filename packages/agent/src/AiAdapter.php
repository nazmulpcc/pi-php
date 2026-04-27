<?php

declare(strict_types=1);

namespace Pi\Agent;

use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ThinkingContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\ToolResultMessage;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\Tool\AgentTool;
use Pi\AI\Api as AiApi;
use Pi\AI\CancellationToken as AiCancellationToken;
use Pi\AI\Content\ImageContent as AiImageContent;
use Pi\AI\Content\TextContent as AiTextContent;
use Pi\AI\Content\ThinkingContent as AiThinkingContent;
use Pi\AI\Content\ToolCall as AiToolCall;
use Pi\AI\Context as AiContext;
use Pi\AI\Message\AssistantMessage as AiAssistantMessage;
use Pi\AI\Message\Message as AiMessage;
use Pi\AI\Message\ToolResultMessage as AiToolResultMessage;
use Pi\AI\Message\UserMessage as AiUserMessage;
use Pi\AI\Provider as AiProvider;
use Pi\AI\Schema\Schema as AiSchema;
use Pi\AI\StopReason as AiStopReason;
use Pi\AI\Tool as AiTool;
use Pi\AI\Usage;

final class AiAdapter
{
    public static function toAiContext(AgentContext $context): AiContext
    {
        return new AiContext(
            messages: array_map([self::class, 'toAiMessage'], $context->messages),
            systemPrompt: $context->systemPrompt,
            tools: array_map([self::class, 'toAiTool'], $context->tools),
        );
    }

    public static function toAgentAssistantMessage(AiAssistantMessage $message): AssistantMessage
    {
        return new AssistantMessage(
            content: array_map([self::class, 'toAgentAssistantContent'], $message->content),
            api: $message->api->value,
            provider: $message->provider->value,
            model: $message->model,
            stopReason: self::toAgentStopReason($message->stopReason),
            timestamp: $message->timestamp,
            errorMessage: $message->errorMessage,
        );
    }

    public static function toAiCancellation(?CancellationToken $token): ?AiCancellationToken
    {
        if ($token === null) {
            return null;
        }

        return new class($token) implements AiCancellationToken
        {
            public function __construct(
                private readonly CancellationToken $token,
            ) {}

            public function isCancelled(): bool
            {
                return $this->token->isCancelled();
            }
        };
    }

    private static function toAiMessage(AgentMessage $message): AiMessage
    {
        if ($message instanceof UserMessage) {
            return new AiUserMessage(
                content: array_map([self::class, 'toAiUserContent'], $message->content),
                timestamp: $message->timestamp,
            );
        }

        if ($message instanceof AssistantMessage) {
            return new AiAssistantMessage(
                content: array_map([self::class, 'toAiAssistantContent'], $message->content),
                api: new AiApi($message->api),
                provider: new AiProvider($message->provider),
                model: $message->model,
                usage: Usage::zero(),
                stopReason: self::toAiStopReason($message->stopReason),
                timestamp: $message->timestamp,
                errorMessage: $message->errorMessage,
            );
        }

        if ($message instanceof ToolResultMessage) {
            return new AiToolResultMessage(
                toolCallId: $message->toolCallId,
                toolName: $message->toolName,
                content: array_map([self::class, 'toAiToolResultContent'], $message->content),
                isError: $message->isError,
                timestamp: $message->timestamp,
                details: $message->details,
            );
        }

        throw new \RuntimeException('Unsupported agent message type for AI conversion.');
    }

    private static function toAiTool(AgentTool $tool): AiTool
    {
        $parameters = $tool->getParameters();

        return new AiTool(
            name: $tool->getName(),
            description: $tool->getDescription(),
            parameters: $parameters instanceof AiSchema ? $parameters : $parameters,
        );
    }

    private static function toAiUserContent(TextContent|ImageContent $content): AiTextContent|AiImageContent
    {
        if ($content instanceof TextContent) {
            return new AiTextContent($content->text);
        }

        return new AiImageContent($content->data, $content->mimeType);
    }

    private static function toAiToolResultContent(TextContent|ImageContent $content): AiTextContent|AiImageContent
    {
        return self::toAiUserContent($content);
    }

    private static function toAiAssistantContent(TextContent|ThinkingContent|ToolCall $content): AiTextContent|AiThinkingContent|AiToolCall
    {
        if ($content instanceof TextContent) {
            return new AiTextContent($content->text);
        }

        if ($content instanceof ThinkingContent) {
            return new AiThinkingContent($content->thinking, $content->thinkingSignature, $content->redacted);
        }

        return new AiToolCall($content->id, $content->name, $content->arguments, $content->thoughtSignature);
    }

    private static function toAgentAssistantContent(AiTextContent|AiThinkingContent|AiToolCall $content): TextContent|ThinkingContent|ToolCall
    {
        if ($content instanceof AiTextContent) {
            return new TextContent($content->text);
        }

        if ($content instanceof AiThinkingContent) {
            return new ThinkingContent($content->thinking, $content->thinkingSignature, $content->redacted);
        }

        return new ToolCall($content->id, $content->name, $content->arguments, $content->thoughtSignature);
    }

    private static function toAiStopReason(StopReason $reason): AiStopReason
    {
        return match ($reason) {
            StopReason::Done => AiStopReason::Stop,
            StopReason::Length => AiStopReason::Length,
            StopReason::ToolCalls => AiStopReason::ToolUse,
            StopReason::Error => AiStopReason::Error,
            StopReason::Aborted => AiStopReason::Aborted,
        };
    }

    private static function toAgentStopReason(AiStopReason $reason): StopReason
    {
        return match ($reason) {
            AiStopReason::Stop => StopReason::Done,
            AiStopReason::Length => StopReason::Length,
            AiStopReason::ToolUse => StopReason::ToolCalls,
            AiStopReason::Error => StopReason::Error,
            AiStopReason::Aborted => StopReason::Aborted,
        };
    }
}
