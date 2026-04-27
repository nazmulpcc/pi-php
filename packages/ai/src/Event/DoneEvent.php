<?php

declare(strict_types=1);

namespace Pi\AI\Event;

use Pi\AI\Message\AssistantMessage;
use Pi\AI\StopReason;

readonly class DoneEvent implements AssistantMessageEvent
{
    public function __construct(
        public StopReason $reason,
        public AssistantMessage $message,
    ) {
        if (! in_array($this->reason, [StopReason::Stop, StopReason::Length, StopReason::ToolUse], true)) {
            throw new \InvalidArgumentException('DoneEvent requires a successful stop reason.');
        }
    }

    public function getType(): AssistantMessageEventType
    {
        return AssistantMessageEventType::Done;
    }
}
