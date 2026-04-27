<?php

declare(strict_types=1);

namespace Pi\AI\Event;

use Pi\AI\Message\AssistantMessage;
use Pi\AI\StopReason;

readonly class ErrorEvent implements AssistantMessageEvent
{
    public function __construct(
        public StopReason $reason,
        public AssistantMessage $error,
    ) {
        if (! in_array($this->reason, [StopReason::Error, StopReason::Aborted], true)) {
            throw new \InvalidArgumentException('ErrorEvent requires an error stop reason.');
        }
    }

    public function getType(): AssistantMessageEventType
    {
        return AssistantMessageEventType::Error;
    }
}
