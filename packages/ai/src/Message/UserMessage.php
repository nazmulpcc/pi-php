<?php

declare(strict_types=1);

namespace Pi\AI\Message;

use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\MessageRole;

readonly class UserMessage implements Message
{
    /**
     * @param  string|array<TextContent|ImageContent>  $content
     */
    public function __construct(
        public string|array $content,
        public int $timestamp,
    ) {}

    public function getRole(): MessageRole
    {
        return MessageRole::User;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
