<?php

declare(strict_types=1);

namespace Pi\Agent;

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
use Pi\Agent\Event\ToolExecutionUpdateEvent;
use Pi\Agent\Event\TurnEndEvent;
use Pi\Agent\Event\TurnStartEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\ToolResultMessage;
use Pi\Agent\Tool\AgentToolResult;
use Pi\AI\AssistantMessageEventStream as AiAssistantMessageEventStream;
use Pi\AI\Event\DoneEvent as AiDoneEvent;
use Pi\AI\Event\ErrorEvent as AiErrorEvent;
use Pi\AI\Event\StartEvent as AiStartEvent;
use Pi\AI\Model;
use Pi\AI\StreamOptions;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

class AgentLoop
{
    /**
     * @param  array<AgentMessage>  $prompts
     * @return PromiseInterface<array<AgentMessage>>
     */
    public function agentLoop(
        array $prompts,
        AgentContext $context,
        AgentLoopConfig $config,
        ?CancellationToken $signal = null,
        ?callable $streamFn = null,
    ): PromiseInterface {
        $newMessages = [...$prompts];
        $currentContext = new AgentContext(
            $context->systemPrompt,
            [...$context->messages, ...$prompts],
            $context->tools,
        );

        return $this->emit(new AgentStartEvent, $config)
            ->then(fn () => $this->emit(new TurnStartEvent, $config))
            ->then(function () use ($prompts, $config) {
                $promise = resolve(null);
                foreach ($prompts as $prompt) {
                    $promise = $promise
                        ->then(fn () => $this->emit(new MessageStartEvent($prompt), $config))
                        ->then(fn () => $this->emit(new MessageEndEvent($prompt), $config));
                }

                return $promise;
            })
            ->then(function () use ($currentContext, &$newMessages, $config, $signal, $streamFn) {
                return $this->runOuterLoop($currentContext, $newMessages, $config, $signal, $streamFn);
            })
            ->then(function () use (&$newMessages) {
                return $newMessages;
            });
    }

    /**
     * @return PromiseInterface<array<AgentMessage>>
     */
    public function agentLoopContinue(
        AgentContext $context,
        AgentLoopConfig $config,
        ?CancellationToken $signal = null,
        ?callable $streamFn = null,
    ): PromiseInterface {
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

        return $this->emit(new AgentStartEvent, $config)
            ->then(fn () => $this->emit(new TurnStartEvent, $config))
            ->then(function () use ($currentContext, &$newMessages, $config, $signal, $streamFn) {
                return $this->runOuterLoop($currentContext, $newMessages, $config, $signal, $streamFn);
            })
            ->then(function () use (&$newMessages) {
                return $newMessages;
            });
    }

    /**
     * @param  array<AgentMessage>  $newMessages
     * @param  array<AgentMessage>  $pendingMessages
     * @return PromiseInterface<void>
     */
    private function runOuterLoop(
        AgentContext $currentContext,
        array &$newMessages,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
        ?callable $streamFn,
        array $pendingMessages = [],
    ): PromiseInterface {
        $promise = count($pendingMessages) > 0
            ? resolve($pendingMessages)
            : PromiseHelper::resolve($config->getSteeringMessages !== null ? ($config->getSteeringMessages)() : []);

        return $promise
            ->then(function (array $msgs) use ($currentContext, &$newMessages, $config, $signal, $streamFn) {
                return $this->runToolCallLoop($currentContext, $newMessages, $config, $signal, $streamFn, true, $msgs);
            })
            ->then(function (bool $terminatedEarly) use ($currentContext, &$newMessages, $config, $signal, $streamFn) {
                if ($terminatedEarly) {
                    return resolve(null);
                }

                return PromiseHelper::resolve($config->getFollowUpMessages !== null ? ($config->getFollowUpMessages)() : [])
                    ->then(function (array $followUpMessages) use ($currentContext, &$newMessages, $config, $signal, $streamFn) {
                        if (count($followUpMessages) > 0) {
                            return $this->runOuterLoop($currentContext, $newMessages, $config, $signal, $streamFn, $followUpMessages);
                        }

                        return $this->emit(new AgentEndEvent($newMessages), $config);
                    });
            });
    }

    /**
     * @param  array<AgentMessage>  $newMessages
     * @param  array<AgentMessage>  $pendingMessages
     * @return PromiseInterface<bool> True if the loop terminated early (error/aborted)
     */
    private function runToolCallLoop(
        AgentContext $currentContext,
        array &$newMessages,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
        ?callable $streamFn,
        bool $firstTurn,
        array $pendingMessages,
        bool $hasMoreToolCalls = true,
    ): PromiseInterface {
        if (! $hasMoreToolCalls && count($pendingMessages) === 0) {
            return resolve(false);
        }

        $promise = resolve(null);

        if (! $firstTurn) {
            $promise = $promise->then(fn () => $this->emit(new TurnStartEvent, $config));
        }

        if (count($pendingMessages) > 0) {
            foreach ($pendingMessages as $message) {
                $promise = $promise
                    ->then(fn () => $this->emit(new MessageStartEvent($message), $config))
                    ->then(function () use ($message, $currentContext, &$newMessages, $config) {
                        $currentContext->messages[] = $message;
                        $newMessages[] = $message;

                        return $this->emit(new MessageEndEvent($message), $config);
                    });
            }
            $pendingMessages = [];
        }

        return $promise
            ->then(fn () => $this->streamAssistantResponse($currentContext, $config, $signal, $streamFn))
            ->then(function (AssistantMessage $message) use ($currentContext, &$newMessages, $config, $signal, $streamFn) {
                $newMessages[] = $message;

                if ($message->stopReason === StopReason::Error || $message->stopReason === StopReason::Aborted) {
                    return $this->emit(new TurnEndEvent($message, []), $config)
                        ->then(fn () => $this->emit(new AgentEndEvent($newMessages), $config))
                        ->then(fn () => true);
                }

                $toolCalls = array_filter(
                    $message->content,
                    fn ($c) => $c instanceof ToolCall,
                );

                if (count($toolCalls) === 0) {
                    return $this->emit(new TurnEndEvent($message, []), $config)
                        ->then(function () use ($config, $currentContext, &$newMessages, $signal, $streamFn) {
                            return PromiseHelper::resolve($config->getSteeringMessages !== null ? ($config->getSteeringMessages)() : [])
                                ->then(function (array $steeringMessages) use ($currentContext, &$newMessages, $config, $signal, $streamFn) {
                                    return $this->runToolCallLoop($currentContext, $newMessages, $config, $signal, $streamFn, false, $steeringMessages, false);
                                });
                        });
                }

                return $this->executeToolCalls($currentContext, $message, $config, $signal)
                    ->then(function (array $executedToolBatch) use ($message, $currentContext, &$newMessages, $config, $signal, $streamFn) {
                        $toolResults = $executedToolBatch['messages'];
                        $hasMoreToolCalls = ! $executedToolBatch['terminate'];

                        foreach ($toolResults as $result) {
                            $currentContext->messages[] = $result;
                            $newMessages[] = $result;
                        }

                        return $this->emit(new TurnEndEvent($message, $toolResults), $config)
                            ->then(function () use ($config, $currentContext, &$newMessages, $signal, $streamFn, $hasMoreToolCalls) {
                                return PromiseHelper::resolve($config->getSteeringMessages !== null ? ($config->getSteeringMessages)() : [])
                                    ->then(function (array $steeringMessages) use ($currentContext, &$newMessages, $config, $signal, $streamFn, $hasMoreToolCalls) {
                                        return $this->runToolCallLoop($currentContext, $newMessages, $config, $signal, $streamFn, false, $steeringMessages, $hasMoreToolCalls);
                                    });
                            });
                    });
            });
    }

    /**
     * @return PromiseInterface<AssistantMessage>
     */
    private function streamAssistantResponse(
        AgentContext $context,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
        ?callable $streamFn,
    ): PromiseInterface {
        $messages = $context->messages;

        return PromiseHelper::resolve($config->transformContext !== null ? ($config->transformContext)($messages, $signal) : $messages)
            ->then(function (array $transformedMessages) use ($config, $signal) {
                return PromiseHelper::resolve(($config->convertToLlm)($transformedMessages, $signal));
            })
            ->then(function (array $llmMessages) use ($context, $config, $signal, $streamFn) {
                $llmContext = new AgentContext(
                    $context->systemPrompt,
                    $llmMessages,
                    $context->tools,
                );

                $streamFunction = $streamFn ?? [$this, 'defaultStream'];

                try {
                    $response = $streamFunction($config->model, $llmContext, $config, $signal);
                } catch (\Throwable $error) {
                    return reject($error);
                }

                return PromiseHelper::resolve($response);
            })
            ->then(function (mixed $response) use ($context, $config) {
                if ($response instanceof AiAssistantMessageEventStream) {
                    return $this->consumeAiStream($response, $context, $config);
                }

                $partialMessage = null;
                $addedPartial = false;
                $finalMessage = null;
                $emitPromise = resolve(null);

                foreach ($response as $event) {
                    $type = $event['type'] ?? '';

                    if ($type === 'start') {
                        $partialMessage = $event['partial'];
                        $context->messages[] = $partialMessage;
                        $addedPartial = true;
                        $msg = $partialMessage;
                        $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageStartEvent($msg), $config));
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
                            $rawEvent = $event['raw'] ?? null;
                            $msg = $partialMessage;
                            $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageUpdateEvent($msg, $rawEvent), $config));
                        }
                    } elseif (in_array($type, ['done', 'error'], true)) {
                        $finalMessage = $event['message'];
                        if ($addedPartial) {
                            $context->messages[count($context->messages) - 1] = $finalMessage;
                        } else {
                            $context->messages[] = $finalMessage;
                        }
                        if (! $addedPartial) {
                            $msg = $finalMessage;
                            $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageStartEvent($msg), $config));
                        }
                        $msg = $finalMessage;
                        $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageEndEvent($msg), $config));
                    }
                }

                if ($finalMessage === null) {
                    $finalMessage = $response->getReturn();
                    if ($addedPartial) {
                        $context->messages[count($context->messages) - 1] = $finalMessage;
                    } else {
                        $context->messages[] = $finalMessage;
                        $msg = $finalMessage;
                        $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageStartEvent($msg), $config));
                    }
                    $msg = $finalMessage;
                    $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageEndEvent($msg), $config));
                }

                return $emitPromise->then(fn () => $finalMessage);
            });
    }

    private function defaultStream(mixed $model, AgentContext $context, AgentLoopConfig $config, ?CancellationToken $signal): PromiseInterface
    {
        if (! $model instanceof Model) {
            return reject(new \RuntimeException('No stream function provided'));
        }

        return PromiseHelper::resolve($config->getApiKey !== null ? ($config->getApiKey)($model->provider->value) : null)
            ->then(function (?string $apiKey) use ($model, $context, $signal) {
                return \Pi\AI\stream(
                    $model,
                    AiAdapter::toAiContext($context),
                    new StreamOptions(
                        apiKey: $apiKey,
                        signal: AiAdapter::toAiCancellation($signal),
                    ),
                );
            });
    }

    /**
     * @return PromiseInterface<AssistantMessage>
     */
    private function consumeAiStream(AiAssistantMessageEventStream $response, AgentContext $context, AgentLoopConfig $config): PromiseInterface
    {
        return $this->doConsumeAiStream($response, $context, $config, null, false, null, resolve(null));
    }

    /**
     * @return PromiseInterface<AssistantMessage>
     */
    private function doConsumeAiStream(
        AiAssistantMessageEventStream $response,
        AgentContext $context,
        AgentLoopConfig $config,
        ?AssistantMessage $partialMessage,
        bool $addedPartial,
        ?AssistantMessage $finalMessage,
        PromiseInterface $emitPromise,
    ): PromiseInterface {
        return $response->next()->then(function ($event) use ($response, $context, $config, $partialMessage, $addedPartial, $finalMessage, $emitPromise) {
            if ($event === null) {
                if ($finalMessage !== null) {
                    return $emitPromise->then(fn () => $finalMessage);
                }

                return $response->result()->then(function ($result) use ($context, $config, $addedPartial, $emitPromise) {
                    $finalMessage = AiAdapter::toAgentAssistantMessage($result);
                    if ($addedPartial) {
                        $context->messages[count($context->messages) - 1] = $finalMessage;
                    } else {
                        $context->messages[] = $finalMessage;
                        $msg = $finalMessage;
                        $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageStartEvent($msg), $config));
                    }
                    $msg = $finalMessage;
                    $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageEndEvent($msg), $config));

                    return $emitPromise->then(fn () => $finalMessage);
                });
            }

            if ($event instanceof AiStartEvent) {
                $partialMessage = AiAdapter::toAgentAssistantMessage($event->partial);
                $context->messages[] = $partialMessage;
                $addedPartial = true;
                $msg = $partialMessage;
                $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageStartEvent($msg), $config));

                return $this->doConsumeAiStream($response, $context, $config, $partialMessage, $addedPartial, $finalMessage, $emitPromise);
            }

            if ($event instanceof AiDoneEvent) {
                $finalMessage = AiAdapter::toAgentAssistantMessage($event->message);
            } elseif ($event instanceof AiErrorEvent) {
                $finalMessage = AiAdapter::toAgentAssistantMessage($event->error);
            } else {
                $partialMessage = AiAdapter::toAgentAssistantMessage($event->partial);
                if ($addedPartial) {
                    $context->messages[count($context->messages) - 1] = $partialMessage;
                }
                $msg = $partialMessage;
                $rawEvent = $event;
                $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageUpdateEvent($msg, $rawEvent), $config));

                return $this->doConsumeAiStream($response, $context, $config, $partialMessage, $addedPartial, $finalMessage, $emitPromise);
            }

            if ($finalMessage !== null) {
                if ($addedPartial) {
                    $context->messages[count($context->messages) - 1] = $finalMessage;
                } else {
                    $context->messages[] = $finalMessage;
                    $msg = $finalMessage;
                    $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageStartEvent($msg), $config));
                }

                $msg = $finalMessage;
                $emitPromise = $emitPromise->then(fn () => $this->emit(new MessageEndEvent($msg), $config));
            }

            return $this->doConsumeAiStream($response, $context, $config, $partialMessage, $addedPartial, $finalMessage, $emitPromise);
        });
    }

    /**
     * @return PromiseInterface<array{messages: array<ToolResultMessage>, terminate: bool}>
     */
    private function executeToolCalls(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): PromiseInterface {
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
            return $this->executeToolCallsSequential($currentContext, $assistantMessage, $toolCalls, $config, $signal);
        }

        return $this->executeToolCallsParallel($currentContext, $assistantMessage, $toolCalls, $config, $signal);
    }

    /**
     * @param  array<ToolCall>  $toolCalls
     * @return PromiseInterface<array{messages: array<ToolResultMessage>, terminate: bool}>
     */
    private function executeToolCallsSequential(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        array $toolCalls,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): PromiseInterface {
        $promise = resolve(null);
        $finalizedCalls = [];
        $messages = [];

        foreach ($toolCalls as $toolCall) {
            $promise = $promise
                ->then(fn () => $this->emit(new ToolExecutionStartEvent($toolCall->id, $toolCall->name, $toolCall->arguments), $config))
                ->then(function () use ($currentContext, $assistantMessage, $toolCall, $config, $signal) {
                    return $this->prepareToolCall($currentContext, $assistantMessage, $toolCall, $config, $signal)
                        ->then(function (array $preparation) use ($signal, $currentContext, $assistantMessage, $config) {
                            if ($preparation['kind'] === 'immediate') {
                                return resolve([
                                    'toolCall' => $preparation['toolCall'],
                                    'result' => $preparation['result'],
                                    'isError' => $preparation['isError'],
                                ]);
                            }

                            return $this->executePreparedToolCall($preparation, $signal, $config)
                                ->then(function (array $executed) use ($currentContext, $assistantMessage, $preparation, $config, $signal) {
                                    return $this->finalizeExecutedToolCall($currentContext, $assistantMessage, $preparation, $executed, $config, $signal);
                                });
                        });
                })
                ->then(function (array $finalized) use ($toolCall, $config, &$finalizedCalls, &$messages) {
                    $finalizedCalls[] = $finalized;

                    return $this->emit(new ToolExecutionEndEvent($toolCall->id, $toolCall->name, $finalized['result'], $finalized['isError']), $config)
                        ->then(function () use ($finalized, &$messages, $config) {
                            $toolResultMessage = $this->createToolResultMessage($finalized);
                            $messages[] = $toolResultMessage;

                            return $this->emit(new MessageStartEvent($toolResultMessage), $config)
                                ->then(fn () => $this->emit(new MessageEndEvent($toolResultMessage), $config));
                        });
                });
        }

        return $promise->then(function () use (&$messages, &$finalizedCalls) {
            return [
                'messages' => $messages,
                'terminate' => $this->shouldTerminateToolBatch($finalizedCalls),
            ];
        });
    }

    /**
     * @param  array<ToolCall>  $toolCalls
     * @return PromiseInterface<array{messages: array<ToolResultMessage>, terminate: bool}>
     */
    private function executeToolCallsParallel(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        array $toolCalls,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): PromiseInterface {
        $promises = [];

        foreach ($toolCalls as $toolCall) {
            $promises[] = $this->emit(new ToolExecutionStartEvent($toolCall->id, $toolCall->name, $toolCall->arguments), $config)
                ->then(function () use ($currentContext, $assistantMessage, $toolCall, $config, $signal) {
                    return $this->prepareToolCall($currentContext, $assistantMessage, $toolCall, $config, $signal)
                        ->then(function (array $preparation) use ($signal, $currentContext, $assistantMessage, $config) {
                            if ($preparation['kind'] === 'immediate') {
                                return resolve([
                                    'toolCall' => $preparation['toolCall'],
                                    'result' => $preparation['result'],
                                    'isError' => $preparation['isError'],
                                ]);
                            }

                            return $this->executePreparedToolCall($preparation, $signal, $config)
                                ->then(function (array $executed) use ($currentContext, $assistantMessage, $preparation, $config, $signal) {
                                    return $this->finalizeExecutedToolCall($currentContext, $assistantMessage, $preparation, $executed, $config, $signal);
                                });
                        });
                })
                ->then(function (array $finalized) use ($toolCall, $config) {
                    return $this->emit(new ToolExecutionEndEvent($toolCall->id, $toolCall->name, $finalized['result'], $finalized['isError']), $config)
                        ->then(fn () => $finalized);
                });
        }

        return PromiseHelper::all($promises)
            ->then(function (array $finalizedArray) use ($config) {
                $messages = [];
                $promise = resolve(null);

                foreach ($finalizedArray as $finalized) {
                    $toolResultMessage = $this->createToolResultMessage($finalized);
                    $messages[] = $toolResultMessage;
                    $promise = $promise
                        ->then(fn () => $this->emit(new MessageStartEvent($toolResultMessage), $config))
                        ->then(fn () => $this->emit(new MessageEndEvent($toolResultMessage), $config));
                }

                return $promise->then(function () use ($messages, $finalizedArray) {
                    return [
                        'messages' => $messages,
                        'terminate' => $this->shouldTerminateToolBatch($finalizedArray),
                    ];
                });
            });
    }

    /**
     * @return PromiseInterface<array{kind: string, toolCall?: ToolCall, result?: AgentToolResult, isError?: bool, tool?: mixed, args?: array}>
     */
    private function prepareToolCall(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        ToolCall $toolCall,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): PromiseInterface {
        $tool = null;
        foreach ($currentContext->tools as $t) {
            if ($t->getName() === $toolCall->name) {
                $tool = $t;
                break;
            }
        }

        if ($tool === null) {
            return resolve([
                'kind' => 'immediate',
                'toolCall' => $toolCall,
                'result' => $this->createErrorToolResult("Tool {$toolCall->name} not found"),
                'isError' => true,
            ]);
        }

        try {
            $preparedArgs = $tool->prepareArguments($toolCall->arguments);
        } catch (\Throwable $error) {
            return resolve([
                'kind' => 'immediate',
                'toolCall' => $toolCall,
                'result' => $this->createErrorToolResult($error->getMessage()),
                'isError' => true,
            ]);
        }

        if ($config->beforeToolCall !== null) {
            try {
                $beforePromise = PromiseHelper::resolve(($config->beforeToolCall)([
                    'assistantMessage' => $assistantMessage,
                    'toolCall' => $toolCall,
                    'args' => $preparedArgs,
                    'context' => $currentContext,
                ], $signal));
            } catch (\Throwable $error) {
                return resolve([
                    'kind' => 'immediate',
                    'toolCall' => $toolCall,
                    'result' => $this->createErrorToolResult($error->getMessage()),
                    'isError' => true,
                ]);
            }

            return $beforePromise->then(function ($beforeResult) use ($toolCall, $tool, $preparedArgs) {
                if (is_array($beforeResult) && ($beforeResult['block'] ?? false)) {
                    return [
                        'kind' => 'immediate',
                        'toolCall' => $toolCall,
                        'result' => $this->createErrorToolResult($beforeResult['reason'] ?? 'Tool execution was blocked'),
                        'isError' => true,
                    ];
                }

                return [
                    'kind' => 'prepared',
                    'toolCall' => $toolCall,
                    'tool' => $tool,
                    'args' => $preparedArgs,
                ];
            })->catch(function (\Throwable $error) use ($toolCall) {
                return [
                    'kind' => 'immediate',
                    'toolCall' => $toolCall,
                    'result' => $this->createErrorToolResult($error->getMessage()),
                    'isError' => true,
                ];
            });
        }

        return resolve([
            'kind' => 'prepared',
            'toolCall' => $toolCall,
            'tool' => $tool,
            'args' => $preparedArgs,
        ]);
    }

    /**
     * @return PromiseInterface<array{result: AgentToolResult, isError: bool, updateEvents: array<ToolExecutionUpdateEvent>}>
     */
    private function executePreparedToolCall(array $preparation, ?CancellationToken $signal, AgentLoopConfig $config): PromiseInterface
    {
        $updateEvents = [];
        $emitPromises = [];

        $onUpdate = function (AgentToolResult $partialResult) use (&$updateEvents, &$emitPromises, $preparation, $config): void {
            $updateEvent = new ToolExecutionUpdateEvent(
                $preparation['toolCall']->id,
                $preparation['toolCall']->name,
                $preparation['toolCall']->arguments,
                $partialResult,
            );
            $updateEvents[] = $updateEvent;
            $emitPromises[] = $this->emit($updateEvent, $config);
        };

        try {
            $promise = PromiseHelper::resolve($preparation['tool']->execute(
                $preparation['toolCall']->id,
                $preparation['args'],
                $signal,
                $onUpdate,
            ));
        } catch (\Throwable $error) {
            return resolve([
                'result' => $this->createErrorToolResult($error->getMessage()),
                'isError' => true,
                'updateEvents' => [],
            ]);
        }

        return $promise
            ->then(function (AgentToolResult $result) use (&$updateEvents, &$emitPromises) {
                return PromiseHelper::all($emitPromises)->then(fn () => [
                    'result' => $result,
                    'isError' => false,
                    'updateEvents' => $updateEvents,
                ]);
            })
            ->catch(function (\Throwable $error) {
                return [
                    'result' => $this->createErrorToolResult($error->getMessage()),
                    'isError' => true,
                    'updateEvents' => [],
                ];
            });
    }

    /**
     * @return PromiseInterface<array{toolCall: ToolCall, result: AgentToolResult, isError: bool}>
     */
    private function finalizeExecutedToolCall(
        AgentContext $currentContext,
        AssistantMessage $assistantMessage,
        array $prepared,
        array $executed,
        AgentLoopConfig $config,
        ?CancellationToken $signal,
    ): PromiseInterface {
        $result = $executed['result'];
        $isError = $executed['isError'];

        if ($config->afterToolCall !== null) {
            try {
                $afterPromise = PromiseHelper::resolve(($config->afterToolCall)([
                    'assistantMessage' => $assistantMessage,
                    'toolCall' => $prepared['toolCall'],
                    'args' => $prepared['args'],
                    'result' => $result,
                    'isError' => $isError,
                    'context' => $currentContext,
                ], $signal));
            } catch (\Throwable $error) {
                return resolve([
                    'toolCall' => $prepared['toolCall'],
                    'result' => $this->createErrorToolResult($error->getMessage()),
                    'isError' => true,
                ]);
            }

            return $afterPromise->then(function ($afterResult) use ($prepared, $result, $isError) {
                if (is_array($afterResult)) {
                    $result = new AgentToolResult(
                        $afterResult['content'] ?? $result->content,
                        $afterResult['details'] ?? $result->details,
                        $afterResult['terminate'] ?? $result->terminate,
                    );
                    $isError = $afterResult['isError'] ?? $isError;
                }

                return [
                    'toolCall' => $prepared['toolCall'],
                    'result' => $result,
                    'isError' => $isError,
                ];
            })->catch(function (\Throwable $error) use ($prepared) {
                return [
                    'toolCall' => $prepared['toolCall'],
                    'result' => $this->createErrorToolResult($error->getMessage()),
                    'isError' => true,
                ];
            });
        }

        return resolve([
            'toolCall' => $prepared['toolCall'],
            'result' => $result,
            'isError' => $isError,
        ]);
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

    private function emit(AgentEvent $event, AgentLoopConfig $config): PromiseInterface
    {
        if ($config->emit !== null) {
            return PromiseHelper::resolve(($config->emit)($event));
        }

        return resolve(null);
    }
}
