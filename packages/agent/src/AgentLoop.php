<?php

declare(strict_types=1);

namespace Pi\Agent;

use Generator;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\Event\AgentEndEvent;
use Pi\Agent\Event\AgentEvent;
use Pi\Agent\Event\AgentStartEvent;
use Pi\Agent\Event\MessageEndEvent;
use Pi\Agent\Event\MessageStartEvent;
use Pi\Agent\Event\MessageUpdateEvent;
use Pi\Agent\Event\ToolExecutionEndEvent;
use Pi\Agent\Event\ToolExecutionStartEvent;
use Pi\Agent\Event\TurnEndEvent;
use Pi\Agent\Event\TurnStartEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\ToolResultMessage;
use Pi\Agent\Tool\AgentToolResult;

class AgentLoop
{
    /**
     * @return Generator<AgentEvent, void, void, array<AgentMessage>>
     */
    public function agentLoop(
        array $prompts,
        AgentContext $context,
        AgentLoopConfig $config,
        ?CancellationToken $signal = null,
        ?callable $streamFn = null,
    ): Generator {
        $newMessages = [...$prompts];
        $currentContext = new AgentContext(
            $context->systemPrompt,
            [...$context->messages, ...$prompts],
            $context->tools,
        );

        yield new AgentStartEvent;
        yield new TurnStartEvent;
        foreach ($prompts as $prompt) {
            yield new MessageStartEvent($prompt);
            yield new MessageEndEvent($prompt);
        }

        yield from $this->runLoop($currentContext, $newMessages, $config, $signal, $streamFn);

        return $newMessages;
    }

    /**
     * @return Generator<AgentEvent, void, void, array<AgentMessage>>
     */
    public function agentLoopContinue(
        AgentContext $context,
        AgentLoopConfig $config,
        ?CancellationToken $signal = null,
        ?callable $streamFn = null,
    ): Generator {
        if (count($context->messages) === 0) {
            throw new \RuntimeException('Cannot continue: no messages in context');
        }

        $lastMessage = $context->messages[count($context->messages) - 1];
        if ($lastMessage->getRole() === MessageRole::Assistant) {
            throw new \RuntimeException('Cannot continue from message role: assistant');
        }

        $newMessages = [];
        $currentContext = new AgentContext(
            $context->systemPrompt,
            $context->messages,
            $context->tools,
        );

        yield new AgentStartEvent;
        yield new TurnStartEvent;

        yield from $this->runLoop($currentContext, $newMessages, $config, $signal, $streamFn);

        return $newMessages;
    }

    /**
     * @param  array<AgentMessage>  $newMessages
     * @return Generator<AgentEvent, void, void, void>
     */
    private function runLoop(
        AgentContext $currentContext,
        array &$newMessages,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
        ?callable $streamFn,
    ): Generator {
        $firstTurn = true;
        $pendingMessages = $config->getSteeringMessages !== null
            ? ($config->getSteeringMessages)()
            : [];

        while (true) {
            $hasMoreToolCalls = true;

            while ($hasMoreToolCalls || count($pendingMessages) > 0) {
                if (! $firstTurn) {
                    yield new TurnStartEvent;
                } else {
                    $firstTurn = false;
                }

                if (count($pendingMessages) > 0) {
                    foreach ($pendingMessages as $message) {
                        yield new MessageStartEvent($message);
                        yield new MessageEndEvent($message);
                        $currentContext->messages[] = $message;
                        $newMessages[] = $message;
                    }
                    $pendingMessages = [];
                }

                $message = yield from $this->streamAssistantResponse($currentContext, $config, $signal, $streamFn);
                $newMessages[] = $message;

                if ($message->stopReason === StopReason::Error || $message->stopReason === StopReason::Aborted) {
                    yield new TurnEndEvent($message, []);
                    yield new AgentEndEvent($newMessages);

                    return;
                }

                $toolCalls = array_filter(
                    $message->content,
                    fn ($c) => $c instanceof ToolCall,
                );

                $toolResults = [];
                $hasMoreToolCalls = false;
                if (count($toolCalls) > 0) {
                    $executedToolBatch = yield from $this->executeToolCalls(
                        $currentContext,
                        $message,
                        $config,
                        $signal,
                    );
                    $toolResults = $executedToolBatch['messages'];
                    $hasMoreToolCalls = ! $executedToolBatch['terminate'];

                    foreach ($toolResults as $result) {
                        $currentContext->messages[] = $result;
                        $newMessages[] = $result;
                    }
                }

                yield new TurnEndEvent($message, $toolResults);

                $pendingMessages = $config->getSteeringMessages !== null
                    ? ($config->getSteeringMessages)()
                    : [];
            }

            $followUpMessages = $config->getFollowUpMessages !== null
                ? ($config->getFollowUpMessages)()
                : [];
            if (count($followUpMessages) > 0) {
                $pendingMessages = $followUpMessages;

                continue;
            }

            break;
        }

        yield new AgentEndEvent($newMessages);
    }

    /**
     * @return Generator<AgentEvent, void, void, AssistantMessage>
     */
    private function streamAssistantResponse(
        AgentContext $context,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
        ?callable $streamFn,
    ): Generator {
        $messages = $context->messages;
        if ($config->transformContext !== null) {
            $messages = ($config->transformContext)($messages, $signal);
        }

        $llmMessages = ($config->convertToLlm)($messages);

        $llmContext = new AgentContext(
            $context->systemPrompt,
            $llmMessages,
            $context->tools,
        );

        $streamFunction = $streamFn ?? [$this, 'defaultStream'];

        $response = $streamFunction($config->model, $llmContext, $config, $signal);

        $partialMessage = null;
        $addedPartial = false;

        foreach ($response as $event) {
            $type = $event['type'] ?? '';

            if ($type === 'start') {
                $partialMessage = $event['partial'];
                $context->messages[] = $partialMessage;
                $addedPartial = true;
                yield new MessageStartEvent($partialMessage);
            } elseif (
                in_array($type, [
                    'text_start', 'text_delta', 'text_end',
                    'thinking_start', 'thinking_delta', 'thinking_end',
                    'toolcall_start', 'toolcall_delta', 'toolcall_end',
                ], true)
            ) {
                if ($partialMessage !== null) {
                    $partialMessage = $event['partial'];
                    $context->messages[count($context->messages) - 1] = $partialMessage;
                    yield new MessageUpdateEvent($partialMessage);
                }
            } elseif (in_array($type, ['done', 'error'], true)) {
                $finalMessage = $event['message'];
                if ($addedPartial) {
                    $context->messages[count($context->messages) - 1] = $finalMessage;
                } else {
                    $context->messages[] = $finalMessage;
                }
                if (! $addedPartial) {
                    yield new MessageStartEvent($finalMessage);
                }
                yield new MessageEndEvent($finalMessage);

                return $finalMessage;
            }
        }

        $finalMessage = $response->getReturn();
        if ($addedPartial) {
            $context->messages[count($context->messages) - 1] = $finalMessage;
        } else {
            $context->messages[] = $finalMessage;
            yield new MessageStartEvent($finalMessage);
        }
        yield new MessageEndEvent($finalMessage);

        return $finalMessage;
    }

    private function defaultStream(mixed $model, AgentContext $context, AgentLoopConfig $config, ?CancellationToken $signal): Generator
    {
        throw new \RuntimeException('No stream function provided');
    }

    /**
     * @param  array<ToolCall>  $toolCalls
     * @return Generator<AgentEvent, void, void, array{messages: array<ToolResultMessage>, terminate: bool}>
     */
    private function executeToolCalls(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): Generator {
        $toolCalls = array_filter(
            $assistantMessage->content,
            fn ($c) => $c instanceof ToolCall,
        );

        $hasSequentialToolCall = false;
        foreach ($toolCalls as $tc) {
            foreach ($currentContext->tools as $t) {
                if ($t->getName() === $tc->name && $t->getExecutionMode() === ToolExecutionMode::Sequential) {
                    $hasSequentialToolCall = true;
                    break 2;
                }
            }
        }

        if ($config->toolExecution === ToolExecutionMode::Sequential || $hasSequentialToolCall) {
            return yield from $this->executeToolCallsSequential($currentContext, $assistantMessage, $toolCalls, $config, $signal);
        }

        return yield from $this->executeToolCallsParallel($currentContext, $assistantMessage, $toolCalls, $config, $signal);
    }

    /**
     * @param  array<ToolCall>  $toolCalls
     * @return Generator<AgentEvent, void, void, array{messages: array<ToolResultMessage>, terminate: bool}>
     */
    private function executeToolCallsSequential(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        array $toolCalls,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): Generator {
        $finalizedCalls = [];
        $messages = [];

        foreach ($toolCalls as $toolCall) {
            yield new ToolExecutionStartEvent($toolCall->id, $toolCall->name, $toolCall->arguments);

            $preparation = $this->prepareToolCall($currentContext, $assistantMessage, $toolCall, $config, $signal);

            if ($preparation['kind'] === 'immediate') {
                $finalized = [
                    'toolCall' => $toolCall,
                    'result' => $preparation['result'],
                    'isError' => $preparation['isError'],
                ];
            } else {
                $executed = $this->executePreparedToolCall($preparation, $signal);
                $finalized = $this->finalizeExecutedToolCall($currentContext, $assistantMessage, $preparation, $executed, $config, $signal);
            }

            yield new ToolExecutionEndEvent($toolCall->id, $toolCall->name, $finalized['result'], $finalized['isError']);
            $toolResultMessage = $this->createToolResultMessage($finalized);
            yield new MessageStartEvent($toolResultMessage);
            yield new MessageEndEvent($toolResultMessage);
            $finalizedCalls[] = $finalized;
            $messages[] = $toolResultMessage;
        }

        return [
            'messages' => $messages,
            'terminate' => $this->shouldTerminateToolBatch($finalizedCalls),
        ];
    }

    /**
     * @param  array<ToolCall>  $toolCalls
     * @return Generator<AgentEvent, void, void, array{messages: array<ToolResultMessage>, terminate: bool}>
     */
    private function executeToolCallsParallel(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        array $toolCalls,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): Generator {
        $finalizedEntries = [];

        foreach ($toolCalls as $toolCall) {
            yield new ToolExecutionStartEvent($toolCall->id, $toolCall->name, $toolCall->arguments);

            $preparation = $this->prepareToolCall($currentContext, $assistantMessage, $toolCall, $config, $signal);

            if ($preparation['kind'] === 'immediate') {
                $finalized = [
                    'toolCall' => $toolCall,
                    'result' => $preparation['result'],
                    'isError' => $preparation['isError'],
                ];
                yield new ToolExecutionEndEvent($toolCall->id, $toolCall->name, $finalized['result'], $finalized['isError']);
                $finalizedEntries[] = $finalized;

                continue;
            }

            $finalizedEntries[] = [
                'preparation' => $preparation,
                'toolCall' => $toolCall,
            ];
        }

        $orderedFinalized = [];
        foreach ($finalizedEntries as $entry) {
            if (isset($entry['result'])) {
                $orderedFinalized[] = $entry;
            } else {
                $executed = $this->executePreparedToolCall($entry['preparation'], $signal);
                $finalized = $this->finalizeExecutedToolCall(
                    $currentContext,
                    $assistantMessage,
                    $entry['preparation'],
                    $executed,
                    $config,
                    $signal,
                );
                yield new ToolExecutionEndEvent($entry['toolCall']->id, $entry['toolCall']->name, $finalized['result'], $finalized['isError']);
                $orderedFinalized[] = $finalized;
            }
        }

        $messages = [];
        foreach ($orderedFinalized as $finalized) {
            $toolResultMessage = $this->createToolResultMessage($finalized);
            yield new MessageStartEvent($toolResultMessage);
            yield new MessageEndEvent($toolResultMessage);
            $messages[] = $toolResultMessage;
        }

        return [
            'messages' => $messages,
            'terminate' => $this->shouldTerminateToolBatch($orderedFinalized),
        ];
    }

    private function prepareToolCall(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        ToolCall $toolCall,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): array {
        $tool = null;
        foreach ($currentContext->tools as $t) {
            if ($t->getName() === $toolCall->name) {
                $tool = $t;
                break;
            }
        }

        if ($tool === null) {
            return [
                'kind' => 'immediate',
                'result' => $this->createErrorToolResult("Tool {$toolCall->name} not found"),
                'isError' => true,
            ];
        }

        try {
            $preparedArgs = $tool->prepareArguments($toolCall->arguments);

            if ($config->beforeToolCall !== null) {
                $beforeResult = ($config->beforeToolCall)([
                    'assistantMessage' => $assistantMessage,
                    'toolCall' => $toolCall,
                    'args' => $preparedArgs,
                    'context' => $currentContext,
                ], $signal);

                if (is_array($beforeResult) && ($beforeResult['block'] ?? false)) {
                    return [
                        'kind' => 'immediate',
                        'result' => $this->createErrorToolResult($beforeResult['reason'] ?? 'Tool execution was blocked'),
                        'isError' => true,
                    ];
                }
            }

            return [
                'kind' => 'prepared',
                'toolCall' => $toolCall,
                'tool' => $tool,
                'args' => $preparedArgs,
            ];
        } catch (\Throwable $error) {
            return [
                'kind' => 'immediate',
                'result' => $this->createErrorToolResult($error->getMessage()),
                'isError' => true,
            ];
        }
    }

    private function executePreparedToolCall(array $preparation, ?CancellationToken $signal): array
    {
        $updateEvents = [];

        try {
            $result = $preparation['tool']->execute(
                $preparation['toolCall']->id,
                $preparation['args'],
                $signal,
                function (AgentToolResult $partialResult) use (&$updateEvents, $preparation): void {
                    $updateEvents[] = [
                        'toolCallId' => $preparation['toolCall']->id,
                        'toolName' => $preparation['toolCall']->name,
                        'args' => $preparation['toolCall']->arguments,
                        'partialResult' => $partialResult,
                    ];
                },
            );

            return ['result' => $result, 'isError' => false];
        } catch (\Throwable $error) {
            return [
                'result' => $this->createErrorToolResult($error->getMessage()),
                'isError' => true,
            ];
        }
    }

    private function finalizeExecutedToolCall(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        array $prepared,
        array $executed,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): array {
        $result = $executed['result'];
        $isError = $executed['isError'];

        if ($config->afterToolCall !== null) {
            try {
                $afterResult = ($config->afterToolCall)([
                    'assistantMessage' => $assistantMessage,
                    'toolCall' => $prepared['toolCall'],
                    'args' => $prepared['args'],
                    'result' => $result,
                    'isError' => $isError,
                    'context' => $currentContext,
                ], $signal);

                if (is_array($afterResult)) {
                    $result = new AgentToolResult(
                        $afterResult['content'] ?? $result->content,
                        $afterResult['details'] ?? $result->details,
                        $afterResult['terminate'] ?? $result->terminate,
                    );
                    $isError = $afterResult['isError'] ?? $isError;
                }
            } catch (\Throwable $error) {
                $result = $this->createErrorToolResult($error->getMessage());
                $isError = true;
            }
        }

        return [
            'toolCall' => $prepared['toolCall'],
            'result' => $result,
            'isError' => $isError,
        ];
    }

    private function shouldTerminateToolBatch(array $finalizedCalls): bool
    {
        if (count($finalizedCalls) === 0) {
            return false;
        }

        foreach ($finalizedCalls as $finalized) {
            if (! $finalized['result']->terminate) {
                return false;
            }
        }

        return true;
    }

    private function createErrorToolResult(string $message): AgentToolResult
    {
        return new AgentToolResult(
            [new TextContent($message)],
            null,
            false,
        );
    }

    private function createToolResultMessage(array $finalized): ToolResultMessage
    {
        return new ToolResultMessage(
            $finalized['toolCall']->id,
            $finalized['toolCall']->name,
            $finalized['result']->content,
            time() * 1000,
            $finalized['isError'],
            $finalized['result']->details,
        );
    }
}
