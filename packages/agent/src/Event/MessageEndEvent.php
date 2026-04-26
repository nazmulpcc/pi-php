<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

use Pi\Agent\AgentMessage;

readonly class MessageEndEvent implements AgentEvent
{
    public function __construct(
        public AgentMessage $message,
    ) {}

    public function getType(): EventType
    {
        return EventType::MessageEnd;
    }
}
