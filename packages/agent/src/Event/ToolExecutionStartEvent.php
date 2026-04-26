<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

readonly class ToolExecutionStartEvent implements AgentEvent
{
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $args,
    ) {}

    public function getType(): EventType
    {
        return EventType::ToolExecutionStart;
    }
}
