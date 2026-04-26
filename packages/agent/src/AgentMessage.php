<?php

declare(strict_types=1);

namespace Pi\Agent;

interface AgentMessage
{
    public function getRole(): MessageRole;

    public function getTimestamp(): int;
}
