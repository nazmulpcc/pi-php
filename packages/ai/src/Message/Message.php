<?php

declare(strict_types=1);

namespace Pi\AI\Message;

use Pi\AI\MessageRole;

interface Message
{
    public function getRole(): MessageRole;

    public function getTimestamp(): int;
}
