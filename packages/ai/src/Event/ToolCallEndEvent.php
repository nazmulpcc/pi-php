<?php

declare(strict_types=1);

namespace Pi\AI\Event;

use Pi\AI\Content\ToolCall;
use Pi\AI\Message\AssistantMessage;

readonly class ToolCallEndEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public ToolCall $toolCall,
        public AssistantMessage $partial,
    ) {}

    public function getType(): AssistantMessageEventType
    {
        return AssistantMessageEventType::ToolCallEnd;
    }
}
