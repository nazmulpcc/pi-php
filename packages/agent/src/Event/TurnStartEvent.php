<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

readonly class TurnStartEvent implements AgentEvent
{
    public function getType(): EventType
    {
        return EventType::TurnStart;
    }
}
