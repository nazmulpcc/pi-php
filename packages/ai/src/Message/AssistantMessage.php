<?php

declare(strict_types=1);

namespace Pi\AI\Message;

use Pi\AI\Api;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\MessageRole;
use Pi\AI\Provider;
use Pi\AI\StopReason;
use Pi\AI\Usage;

readonly class AssistantMessage implements Message
{
    /**
     * @param  array<TextContent|ThinkingContent|ToolCall>  $content
     */
    public function __construct(
        public array $content,
        public Api $api,
        public Provider $provider,
        public string $model,
        public Usage $usage,
        public StopReason $stopReason,
        public int $timestamp,
        public ?string $responseId = null,
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
