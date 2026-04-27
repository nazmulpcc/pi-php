<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $totalTokens = 0,
        public UsageCost $cost = new UsageCost,
    ) {}

    public static function zero(): self
    {
        return new self;
    }
}
