<?php

declare(strict_types=1);

namespace Pi\Agent\Message;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\MessageRole;

readonly class UserMessage implements AgentMessage
{
    /**
     * @param  array<TextContent|ImageContent>  $content
     */
    public function __construct(
        public array $content,
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
