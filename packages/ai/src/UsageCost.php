<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class UsageCost
{
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
        public float $total = 0.0,
    ) {}
}
