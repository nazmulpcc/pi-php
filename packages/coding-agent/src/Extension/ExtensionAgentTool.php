<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\Agent\CancellationToken;
use Pi\Agent\Tool\AgentTool;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Schema;
use React\Promise\PromiseInterface;

final readonly class ExtensionAgentTool implements AgentTool
{
    public function __construct(
        private ExtensionTool $tool,
    ) {}

    public function getName(): string
    {
        return $this->tool->name;
    }

    public function getLabel(): string
    {
        return $this->tool->label;
    }

    public function getDescription(): string
    {
        return $this->tool->description;
    }

    public function getParameters(): array|Schema
    {
        return $this->tool->parameters;
    }

    public function getExecutionMode(): ToolExecutionMode
    {
        return $this->tool->executionMode;
    }

    public function prepareArguments(array $args): array
    {
        if ($this->tool->prepareArguments === null) {
            return $args;
        }

        return ($this->tool->prepareArguments)($args);
    }

    public function execute(
        string $toolCallId,
        array $params,
        ?CancellationToken $signal = null,
        ?callable $onUpdate = null,
    ): PromiseInterface {
        return ($this->tool->execute)($toolCallId, $params, $signal, $onUpdate);
    }
}
