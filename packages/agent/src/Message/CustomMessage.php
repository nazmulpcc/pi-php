<?php

declare(strict_types=1);

namespace Pi\Agent\Message;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\MessageRole;

readonly class CustomMessage implements AgentMessage
{
    /**
     * @param  array<TextContent|ImageContent>  $content
     */
    public function __construct(
        public string $customType,
        public array $content,
        public int $timestamp,
        public bool $display = true,
        public mixed $details = null,
    ) {}

    public function getRole(): MessageRole
    {
        return MessageRole::Custom;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
