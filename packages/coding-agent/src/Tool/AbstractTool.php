<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Tool\AgentTool;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Schema;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

abstract class AbstractTool implements AgentTool
{
    public function __construct(
        protected readonly string $name,
        protected readonly string $description,
        protected readonly array|Schema $parameters,
        protected readonly ToolExecutionMode $executionMode = ToolExecutionMode::Parallel,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getParameters(): array|Schema
    {
        return $this->parameters;
    }

    public function getExecutionMode(): ToolExecutionMode
    {
        return $this->executionMode;
    }

    public function prepareArguments(array $args): array
    {
        return $args;
    }

    public function execute(
        string $toolCallId,
        array $params,
        ?CancellationToken $signal = null,
        ?callable $onUpdate = null,
    ): PromiseInterface {
        if ($signal?->isCancelled()) {
            throw new \RuntimeException('Tool execution aborted');
        }

        return resolve($this->doExecute($toolCallId, $params, $signal, $onUpdate));
    }

    abstract protected function doExecute(
        string $toolCallId,
        array $params,
        ?CancellationToken $signal = null,
        ?callable $onUpdate = null,
    ): AgentToolResult;
}
