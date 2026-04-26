<?php

declare(strict_types=1);

namespace Pi\Agent;

interface CancellationToken
{
    public function isCancelled(): bool;
}
