<?php

declare(strict_types=1);

namespace Pi\AI;

use Pi\AI\Schema\Schema;

readonly class Tool
{
    public function __construct(
        public string $name,
        public string $description,
        public Schema|array $parameters,
    ) {}
}
