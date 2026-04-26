<?php

declare(strict_types=1);

namespace Pi\Agent;

use Pi\Agent\Event\AgentEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Tool\AgentToolResult;

readonly class AgentLoopConfig
{
    /**
     * @param  callable(array<AgentMessage>): array<AgentMessage>  $convertToLlm
     * @param  ?callable(array<AgentMessage>, ?CancellationToken): array<AgentMessage>  $transformContext
     * @param  ?callable(string): ?string  $getApiKey
     * @param  ?callable(): array<AgentMessage>  $getSteeringMessages
     * @param  ?callable(): array<AgentMessage>  $getFollowUpMessages
     * @param  ?callable(array{assistantMessage: AssistantMessage, toolCall: ToolCall, args: mixed, context: AgentContext}, ?CancellationToken): ?array{block: bool, reason?: string}  $beforeToolCall
     * @param  ?callable(array{assistantMessage: AssistantMessage, toolCall: ToolCall, args: mixed, result: AgentToolResult, isError: bool, context: AgentContext}, ?CancellationToken): ?array{content?: array, details?: mixed, isError?: bool, terminate?: bool}  $afterToolCall
     * @param  ?callable(AgentEvent): void  $emit
     */
    public function __construct(
        public mixed $model = null,
        public mixed $convertToLlm = null,
        public mixed $transformContext = null,
        public mixed $getApiKey = null,
        public mixed $getSteeringMessages = null,
        public mixed $getFollowUpMessages = null,
        public ToolExecutionMode $toolExecution = ToolExecutionMode::Parallel,
        public mixed $beforeToolCall = null,
        public mixed $afterToolCall = null,
        public mixed $emit = null,
    ) {}
}
