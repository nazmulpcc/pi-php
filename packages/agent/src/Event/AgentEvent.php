<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

interface AgentEvent
{
    public function getType(): EventType;
}
