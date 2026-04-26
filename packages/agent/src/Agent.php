<?php

declare(strict_types=1);

namespace Pi\Agent;

use Pi\Agent\Content\TextContent;
use Pi\Agent\Event\AgentEndEvent;
use Pi\Agent\Event\AgentEvent;
use Pi\Agent\Event\MessageEndEvent;
use Pi\Agent\Event\MessageStartEvent;
use Pi\Agent\Event\MessageUpdateEvent;
use Pi\Agent\Event\ToolExecutionEndEvent;
use Pi\Agent\Event\ToolExecutionStartEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\UserMessage;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

class Agent
{
    private MutableAgentState $state;

    private array $listeners = [];

    private PendingMessageQueue $steeringQueue;

    private PendingMessageQueue $followUpQueue;

    private bool $isRunning = false;

    private ?CancellationToken $cancellationToken = null;

    private ?PromiseInterface $activeRunPromise = null;

    public mixed $convertToLlm = null;

    public mixed $transformContext = null;

    public mixed $streamFn = null;

    public mixed $getApiKey = null;

    public mixed $beforeToolCall = null;

    public mixed $afterToolCall = null;

    public ?string $sessionId = null;

    public ToolExecutionMode $toolExecution = ToolExecutionMode::Parallel;

    public function __construct(
        ?MutableAgentState $initialState = null,
        ?callable $convertToLlm = null,
        ?callable $transformContext = null,
        ?callable $streamFn = null,
        ?callable $getApiKey = null,
        ?callable $beforeToolCall = null,
        ?callable $afterToolCall = null,
        string $steeringMode = 'one-at-a-time',
        string $followUpMode = 'one-at-a-time',
        ?string $sessionId = null,
        ToolExecutionMode $toolExecution = ToolExecutionMode::Parallel,
        private readonly ?LoopInterface $loop = null,
    ) {
        $this->state = $initialState ?? new MutableAgentState;
        $this->convertToLlm = $convertToLlm ?? fn (array $messages): array => $this->defaultConvertToLlm($messages);
        $this->transformContext = $transformContext;
        $this->streamFn = $streamFn;
        $this->getApiKey = $getApiKey;
        $this->beforeToolCall = $beforeToolCall;
        $this->afterToolCall = $afterToolCall;
        $this->steeringQueue = new PendingMessageQueue($steeringMode);
        $this->followUpQueue = new PendingMessageQueue($followUpMode);
        $this->sessionId = $sessionId;
        $this->toolExecution = $toolExecution;
    }

    public function getState(): MutableAgentState
    {
        return $this->state;
    }

    public function setSteeringMode(string $mode): void
    {
        $this->steeringQueue->setMode($mode);
    }

    public function getSteeringMode(): string
    {
        return $this->steeringQueue->getMode();
    }

    public function setFollowUpMode(string $mode): void
    {
        $this->followUpQueue->setMode($mode);
    }

    public function getFollowUpMode(): string
    {
        return $this->followUpQueue->getMode();
    }

    /**
     * @param  callable(AgentEvent, ?CancellationToken): (void|PromiseInterface<void>)  $listener
     */
    public function subscribe(callable $listener): callable
    {
        $this->listeners[] = $listener;

        return function () use ($listener): void {
            $this->listeners = array_filter(
                $this->listeners,
                fn ($l) => $l !== $listener,
            );
        };
    }

    public function steer(AgentMessage $message): void
    {
        $this->steeringQueue->enqueue($message);
    }

    public function followUp(AgentMessage $message): void
    {
        $this->followUpQueue->enqueue($message);
    }

    public function clearSteeringQueue(): void
    {
        $this->steeringQueue->clear();
    }

    public function clearFollowUpQueue(): void
    {
        $this->followUpQueue->clear();
    }

    public function clearAllQueues(): void
    {
        $this->clearSteeringQueue();
        $this->clearFollowUpQueue();
    }

    public function hasQueuedMessages(): bool
    {
        return $this->steeringQueue->hasItems() || $this->followUpQueue->hasItems();
    }

    public function abort(): void
    {
        if ($this->cancellationToken instanceof SimpleCancellationToken) {
            $this->cancellationToken->cancel();
        }
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    public function reset(): void
    {
        $this->state->setMessages([]);
        $this->state->setIsStreaming(false);
        $this->state->setStreamingMessage(null);
        $this->state->setPendingToolCalls([]);
        $this->state->setErrorMessage(null);
        $this->clearAllQueues();
    }

    /**
     * @return PromiseInterface<void>
     */
    public function prompt(string|AgentMessage|array $input, ?array $images = null): PromiseInterface
    {
        if ($this->isRunning) {
            throw new \RuntimeException('Agent is already processing a prompt. Use steer() or followUp() to queue messages, or wait for completion.');
        }

        $messages = $this->normalizePromptInput($input, $images);

        return $this->runPromptMessages($messages);
    }

    /**
     * @return PromiseInterface<void>
     */
    public function continue(): PromiseInterface
    {
        if ($this->isRunning) {
            throw new \RuntimeException('Agent is already processing. Wait for completion before continuing.');
        }

        $messages = $this->state->getMessages();
        if (count($messages) === 0) {
            throw new \RuntimeException('No messages to continue from');
        }

        $lastMessage = $messages[count($messages) - 1];
        if ($lastMessage->getRole() === MessageRole::Assistant) {
            $queuedSteering = $this->steeringQueue->drain();
            if (count($queuedSteering) > 0) {
                return $this->runPromptMessages($queuedSteering, true);
            }

            $queuedFollowUps = $this->followUpQueue->drain();
            if (count($queuedFollowUps) > 0) {
                return $this->runPromptMessages($queuedFollowUps);
            }

            throw new \RuntimeException('Cannot continue from message role: assistant');
        }

        return $this->runContinuation();
    }

    /**
     * @return PromiseInterface<void>
     */
    public function waitForIdle(): PromiseInterface
    {
        if ($this->activeRunPromise === null) {
            return resolve(null);
        }

        return $this->activeRunPromise;
    }

    private function normalizePromptInput(string|AgentMessage|array $input, ?array $images): array
    {
        if (is_array($input)) {
            return $input;
        }

        if (! is_string($input)) {
            return [$input];
        }

        $content = [new TextContent($input)];
        if ($images !== null && count($images) > 0) {
            $content = array_merge($content, $images);
        }

        return [new UserMessage($content, time() * 1000)];
    }

    private function runPromptMessages(array $messages, bool $skipInitialSteeringPoll = false): PromiseInterface
    {
        return $this->runWithLifecycle(function () use ($messages, $skipInitialSteeringPoll) {
            $loop = new AgentLoop;

            return $loop->agentLoop(
                $messages,
                $this->createContextSnapshot(),
                $this->createLoopConfig($skipInitialSteeringPoll),
                $this->cancellationToken,
                $this->streamFn,
            );
        });
    }

    private function runContinuation(): PromiseInterface
    {
        return $this->runWithLifecycle(function () {
            $loop = new AgentLoop;

            return $loop->agentLoopContinue(
                $this->createContextSnapshot(),
                $this->createLoopConfig(),
                $this->cancellationToken,
                $this->streamFn,
            );
        });
    }

    private function runWithLifecycle(callable $executor): PromiseInterface
    {
        if ($this->isRunning) {
            throw new \RuntimeException('Agent is already processing.');
        }

        $this->cancellationToken = new SimpleCancellationToken;
        $this->isRunning = true;
        $this->state->setIsStreaming(true);
        $this->state->setStreamingMessage(null);
        $this->state->setErrorMessage(null);

        $this->activeRunPromise = PromiseHelper::resolve($executor())
            ->catch(function (\Throwable $error) {
                return $this->handleRunFailure($error, $this->cancellationToken?->isCancelled() ?? false);
            })
            ->finally(function () {
                $this->finishRun();
            });

        return $this->activeRunPromise;
    }

    private function handleRunFailure(\Throwable $error, bool $aborted): PromiseInterface
    {
        $failureMessage = new AssistantMessage(
            [new TextContent('')],
            'unknown',
            'unknown',
            'unknown',
            $aborted ? StopReason::Aborted : StopReason::Error,
            time() * 1000,
            $error->getMessage(),
        );
        $messages = $this->state->getMessages();
        $messages[] = $failureMessage;
        $this->state->setMessages($messages);
        $this->state->setErrorMessage($failureMessage->errorMessage);

        return $this->processEvent(new AgentEndEvent([$failureMessage]));
    }

    private function finishRun(): void
    {
        $this->state->setIsStreaming(false);
        $this->state->setStreamingMessage(null);
        $this->state->setPendingToolCalls([]);
        $this->isRunning = false;
        $this->cancellationToken = null;
        $this->activeRunPromise = null;
    }

    private function createContextSnapshot(): AgentContext
    {
        return new AgentContext(
            $this->state->getSystemPrompt(),
            $this->state->getMessages(),
            $this->state->getTools(),
        );
    }

    private function createLoopConfig(bool $skipInitialSteeringPoll = false): AgentLoopConfig
    {
        $skip = $skipInitialSteeringPoll;

        return new AgentLoopConfig(
            model: null,
            convertToLlm: $this->convertToLlm,
            transformContext: $this->transformContext,
            getApiKey: $this->getApiKey,
            getSteeringMessages: function () use (&$skip): array {
                if ($skip) {
                    $skip = false;

                    return [];
                }

                return $this->steeringQueue->drain();
            },
            getFollowUpMessages: fn (): array => $this->followUpQueue->drain(),
            toolExecution: $this->toolExecution,
            beforeToolCall: $this->beforeToolCall,
            afterToolCall: $this->afterToolCall,
            emit: function (AgentEvent $event): PromiseInterface {
                return $this->processEvent($event);
            },
        );
    }

    private function appendMessage(AgentMessage $message): void
    {
        $this->state->setStreamingMessage(null);
        $messages = $this->state->getMessages();
        $messages[] = $message;
        $this->state->setMessages($messages);
    }

    private function processEvent(AgentEvent $event): PromiseInterface
    {
        match (true) {
            $event instanceof MessageStartEvent => $this->state->setStreamingMessage($event->message),
            $event instanceof MessageUpdateEvent => $this->state->setStreamingMessage($event->message),
            $event instanceof MessageEndEvent => $this->appendMessage($event->message),
            $event instanceof ToolExecutionStartEvent => $this->state->setPendingToolCalls(
                array_merge($this->state->getPendingToolCalls(), [$event->toolCallId]),
            ),
            $event instanceof ToolExecutionEndEvent => $this->state->setPendingToolCalls(
                array_diff($this->state->getPendingToolCalls(), [$event->toolCallId]),
            ),
            $event instanceof AgentEndEvent => $this->state->setStreamingMessage(null),
            default => null,
        };

        $promise = resolve(null);
        foreach ($this->listeners as $listener) {
            $promise = $promise->then(function () use ($listener, $event): PromiseInterface {
                return PromiseHelper::resolve($listener($event, $this->cancellationToken));
            });
        }

        return $promise;
    }

    private function defaultConvertToLlm(array $messages): array
    {
        return array_filter(
            $messages,
            fn (AgentMessage $m) => in_array($m->getRole(), [MessageRole::User, MessageRole::Assistant, MessageRole::ToolResult], true),
        );
    }
}
