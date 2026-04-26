<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

readonly class ToolExecutionEndEvent implements AgentEvent
{
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public mixed $result,
        public bool $isError,
    ) {}

    public function getType(): EventType
    {
        return EventType::ToolExecutionEnd;
    }
}
