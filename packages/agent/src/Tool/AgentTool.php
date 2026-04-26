<?php

declare(strict_types=1);

namespace Pi\Agent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\ToolExecutionMode;
use React\Promise\PromiseInterface;

interface AgentTool
{
    public function getName(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    public function getParameters(): array;

    public function getExecutionMode(): ToolExecutionMode;

    public function prepareArguments(array $args): array;

    /**
     * @return PromiseInterface<AgentToolResult>
     */
    public function execute(
        string $toolCallId,
        array $params,
        ?CancellationToken $signal = null,
        ?callable $onUpdate = null,
    ): PromiseInterface;
}
