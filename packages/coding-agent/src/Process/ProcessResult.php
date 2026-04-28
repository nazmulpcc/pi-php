<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Process;

readonly class ProcessResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public ?int $exitCode,
        public ?int $termSignal = null,
    ) {}
}
