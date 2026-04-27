<?php

declare(strict_types=1);

namespace Pi\AI;

interface CancellationToken
{
    public function isCancelled(): bool;
}
