<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

use Pi\Agent\AgentMessage;

readonly class AgentEndEvent implements AgentEvent
{
    /**
     * @param  array<AgentMessage>  $messages
     */
    public function __construct(
        public array $messages,
    ) {}

    public function getType(): EventType
    {
        return EventType::AgentEnd;
    }
}
