<?php

declare(strict_types=1);

namespace Pi\Agent\Message;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ThinkingContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\MessageRole;
use Pi\Agent\StopReason;

readonly class AssistantMessage implements AgentMessage
{
    /**
     * @param  array<TextContent|ImageContent|ThinkingContent|ToolCall>  $content
     */
    public function __construct(
        public array $content,
        public string $api,
        public string $provider,
        public string $model,
        public StopReason $stopReason,
        public int $timestamp,
        public ?string $errorMessage = null,
    ) {}

    public function getRole(): MessageRole
    {
        return MessageRole::Assistant;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
