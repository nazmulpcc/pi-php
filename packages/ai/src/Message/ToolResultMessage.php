<?php

declare(strict_types=1);

namespace Pi\AI\Message;

use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\MessageRole;

readonly class ToolResultMessage implements Message
{
    /**
     * @param  array<TextContent|ImageContent>  $content
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $content,
        public bool $isError,
        public int $timestamp,
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
