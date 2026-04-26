<?php

declare(strict_types=1);

namespace Pi\Agent\Message;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\MessageRole;

readonly class ToolResultMessage implements AgentMessage
{
    /**
     * @param  array<TextContent|ImageContent>  $content
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $content,
        public int $timestamp,
        public bool $isError = false,
        public mixed $details = null,
    ) {}

    public function getRole(): MessageRole
    {
        return MessageRole::ToolResult;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
