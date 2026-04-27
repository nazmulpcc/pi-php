<?php

declare(strict_types=1);

namespace Pi\AI\Event;

use Pi\AI\Message\AssistantMessage;

readonly class ThinkingEndEvent implements AssistantMessageEvent
{
    public function __construct(
        public int $contentIndex,
        public string $content,
        public AssistantMessage $partial,
    ) {}

    public function getType(): AssistantMessageEventType
    {
        return AssistantMessageEventType::ThinkingEnd;
    }
}
