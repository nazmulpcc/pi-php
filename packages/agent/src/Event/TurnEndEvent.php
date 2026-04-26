<?php

declare(strict_types=1);

namespace Pi\Agent\Event;

use Pi\Agent\AgentMessage;
use Pi\Agent\Message\ToolResultMessage;

readonly class TurnEndEvent implements AgentEvent
{
    /**
     * @param  array<ToolResultMessage>  $toolResults
     */
    public function __construct(
        public AgentMessage $message,
        public array $toolResults,
    ) {}

    public function getType(): EventType
    {
        return EventType::TurnEnd;
    }
}
