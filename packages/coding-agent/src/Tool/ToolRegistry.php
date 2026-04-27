<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\Tool\AgentTool;

final class ToolRegistry
{
    /**
     * @param  array<AgentTool>  $builtIns
     * @param  array<AgentTool>  $customTools
     */
    public function __construct(
        private readonly array $builtIns,
        private readonly array $customTools = [],
    ) {}

    /**
     * @param  array<string>|null  $allowedToolNames
     * @return array<AgentTool>
     */
    public function resolve(?array $allowedToolNames = null): array
    {
        $tools = [...$this->builtIns, ...$this->customTools];
        if ($allowedToolNames === null) {
            return $tools;
        }

        $allowed = array_fill_keys($allowedToolNames, true);

        return array_values(array_filter(
            $tools,
            static fn (AgentTool $tool): bool => isset($allowed[$tool->getName()]),
        ));
    }
}
