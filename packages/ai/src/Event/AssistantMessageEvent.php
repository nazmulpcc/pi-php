<?php

declare(strict_types=1);

namespace Pi\AI\Event;

interface AssistantMessageEvent
{
    public function getType(): AssistantMessageEventType;
}
