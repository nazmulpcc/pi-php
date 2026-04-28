<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Schema;

final readonly class ExtensionTool
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public array|Schema $parameters,
        public \Closure $execute,
        public ToolExecutionMode $executionMode = ToolExecutionMode::Sequential,
        public ?\Closure $prepareArguments = null,
    ) {}
}
