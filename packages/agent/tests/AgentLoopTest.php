<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\AgentContext;
use Pi\Agent\AgentLoop;
use Pi\Agent\AgentLoopConfig;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\Event\AgentEndEvent;
use Pi\Agent\Event\AgentEvent;
use Pi\Agent\Event\ToolExecutionEndEvent;
use Pi\Agent\Event\ToolExecutionStartEvent;
use Pi\Agent\Event\ToolExecutionUpdateEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\StopReason;
use Pi\Agent\Tool\AgentTool;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Faux;
use Pi\AI\Schema\Schema as AiSchema;
use Pi\AI\Schema\Type;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

function createAssistantMessage(array $content, StopReason $stopReason = StopReason::Done): AssistantMessage
{
    return new AssistantMessage(
        $content,
        'openai-responses',
        'openai',
        'mock',
        $stopReason,
        time() * 1000,
    );
}

function createUserMessage(string $text): UserMessage
{
    return new UserMessage([new TextContent($text)], time() * 1000);
}

function identityConverter(array $messages): array
{
    return array_filter(
        $messages,
        fn ($m) => in_array($m->getRole()->value, ['user', 'assistant', 'toolResult'], true),
    );
}

function collectEventsAgentLoopConfig(callable $convertToLlm, array &$events): AgentLoopConfig
{
    return new AgentLoopConfig(
        model: null,
        convertToLlm: $convertToLlm,
        emit: function (AgentEvent $event) use (&$events) {
            $events[] = $event;

            return \React\Promise\resolve(null);
        },
    );
}

describe('AgentLoop', function () {
    it('emits events for a simple response', function () {
        $context = new AgentContext('You are helpful.', [], []);
        $userPrompt = createUserMessage('Hello');

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('Hi there!')])];
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        $eventTypes = array_map(fn ($e) => $e->getType()->value, $events);

        expect($eventTypes)->toContain('agent_start');
        expect($eventTypes)->toContain('turn_start');
        expect($eventTypes)->toContain('message_start');
        expect($eventTypes)->toContain('message_end');
        expect($eventTypes)->toContain('turn_end');
        expect($eventTypes)->toContain('agent_end');
    });

    it('returns messages with user and assistant', function () {
        $context = new AgentContext('You are helpful.', [], []);
        $userPrompt = createUserMessage('Hello');

        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: identityConverter(...),
        );

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('Hi there!')])];
        };

        $loop = new AgentLoop;
        $messages = block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        expect(count($messages))->toBe(2);
        expect($messages[0]->getRole()->value)->toBe('user');
        expect($messages[1]->getRole()->value)->toBe('assistant');
    });

    it('handles tool calls and results', function () {
        $executed = [];
        $tool = new class($executed) implements AgentTool
        {
            public function __construct(private array &$executed) {}

            public function getName(): string
            {
                return 'echo';
            }

            public function getLabel(): string
            {
                return 'Echo';
            }

            public function getDescription(): string
            {
                return 'Echo tool';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function getExecutionMode(): ToolExecutionMode
            {
                return ToolExecutionMode::Parallel;
            }

            public function prepareArguments(array $args): array
            {
                return $args;
            }

            public function execute(string $toolCallId, array $params, $signal = null, $onUpdate = null): PromiseInterface
            {
                $this->executed[] = $params['value'];

                return \React\Promise\resolve(new AgentToolResult([new TextContent("echoed: {$params['value']}")]));
            }
        };

        $context = new AgentContext('', [], [$tool]);
        $userPrompt = createUserMessage('echo something');

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $callIndex = 0;
        $streamFn = function () use (&$callIndex) {
            if ($callIndex === 0) {
                $callIndex++;
                yield ['type' => 'done', 'message' => createAssistantMessage([
                    new ToolCall('tool-1', 'echo', ['value' => 'hello']),
                ], StopReason::Done)];
            } else {
                yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('done')])];
            }
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        expect($executed)->toBe(['hello']);

        $toolStart = array_filter($events, fn ($e) => $e instanceof ToolExecutionStartEvent);
        $toolEnd = array_filter($events, fn ($e) => $e instanceof ToolExecutionEndEvent);
        expect(count($toolStart))->toBeGreaterThan(0);
        expect(count($toolEnd))->toBeGreaterThan(0);
    });

    it('applies transformContext before convertToLlm', function () {
        $context = new AgentContext('You are helpful.', [
            createUserMessage('old message 1'),
            createAssistantMessage([new TextContent('old response 1')]),
            createUserMessage('old message 2'),
            createAssistantMessage([new TextContent('old response 2')]),
        ], []);

        $userPrompt = createUserMessage('new message');

        $transformedMessages = [];
        $convertedMessages = [];

        $config = new AgentLoopConfig(
            model: null,
            transformContext: function (array $messages) use (&$transformedMessages): array {
                $transformedMessages = array_slice($messages, -2);

                return $transformedMessages;
            },
            convertToLlm: function (array $messages) use (&$convertedMessages): array {
                $convertedMessages = $messages;

                return $messages;
            },
        );

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('Response')])];
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        expect(count($transformedMessages))->toBe(2);
        expect(count($convertedMessages))->toBe(2);
    });

    it('filters custom messages via convertToLlm', function () {
        $context = new AgentContext('You are helpful.', [], []);
        $userPrompt = createUserMessage('Hello');

        $convertedMessages = [];
        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: function (array $messages) use (&$convertedMessages): array {
                $convertedMessages = array_filter(
                    $messages,
                    fn ($m) => $m->getRole()->value !== 'notification',
                );

                return $convertedMessages;
            },
        );

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('Response')])];
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        expect(count($convertedMessages))->toBe(1);
        expect($convertedMessages[0]->getRole()->value)->toBe('user');
    });

    it('handles error stop reason', function () {
        $context = new AgentContext('', [], []);
        $userPrompt = createUserMessage('Hello');

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('')], StopReason::Error)];
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(AgentEndEvent::class);
    });

    it('continues from existing context', function () {
        $context = new AgentContext('', [
            createUserMessage('Hello'),
            createAssistantMessage([new TextContent('Hi!')]),
            createUserMessage('How are you?'),
        ], []);

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('I am fine')])];
        };

        $loop = new AgentLoop;
        block($loop->agentLoopContinue($context, $config, null, $streamFn));

        $eventTypes = array_map(fn ($e) => $e->getType()->value, $events);
        expect($eventTypes)->toContain('agent_start');
        expect($eventTypes)->toContain('agent_end');
    });

    it('throws when continuing from assistant message', function () {
        $context = new AgentContext('', [
            createUserMessage('Hello'),
            createAssistantMessage([new TextContent('Hi!')]),
        ], []);

        $config = new AgentLoopConfig(model: null, convertToLlm: identityConverter(...));
        $loop = new AgentLoop;

        expect(function () use ($loop, $context, $config) {
            block($loop->agentLoopContinue($context, $config));
        })->toThrow(RuntimeException::class, 'Cannot continue from message role: assistant');
    });

    it('emits tool_execution_update events', function () {
        $tool = new class implements AgentTool
        {
            public function getName(): string
            {
                return 'updater';
            }

            public function getLabel(): string
            {
                return 'Updater';
            }

            public function getDescription(): string
            {
                return 'Updater tool';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function getExecutionMode(): ToolExecutionMode
            {
                return ToolExecutionMode::Parallel;
            }

            public function prepareArguments(array $args): array
            {
                return $args;
            }

            public function execute(string $toolCallId, array $params, $signal = null, $onUpdate = null): PromiseInterface
            {
                if ($onUpdate !== null) {
                    $onUpdate(new AgentToolResult([new TextContent('partial')]));
                }

                return \React\Promise\resolve(new AgentToolResult([new TextContent('final')]));
            }
        };

        $context = new AgentContext('', [], [$tool]);
        $userPrompt = createUserMessage('update');

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $callIndex = 0;
        $streamFn = function () use (&$callIndex) {
            if ($callIndex === 0) {
                $callIndex++;
                yield ['type' => 'done', 'message' => createAssistantMessage([
                    new ToolCall('tool-1', 'updater', []),
                ], StopReason::Done)];
            } else {
                yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('done')])];
            }
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        $updateEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolExecutionUpdateEvent));
        expect(count($updateEvents))->toBe(1);
        expect($updateEvents[0]->partialResult)->toBeInstanceOf(AgentToolResult::class);
    });

    it('emits tool_execution_update events before the tool promise resolves', function () {
        $deferred = new Deferred;

        $tool = new class($deferred) implements AgentTool
        {
            public function __construct(private Deferred $deferred) {}

            public function getName(): string
            {
                return 'slow-updater';
            }

            public function getLabel(): string
            {
                return 'Slow Updater';
            }

            public function getDescription(): string
            {
                return 'Slow updater tool';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function getExecutionMode(): ToolExecutionMode
            {
                return ToolExecutionMode::Sequential;
            }

            public function prepareArguments(array $args): array
            {
                return $args;
            }

            public function execute(string $toolCallId, array $params, $signal = null, $onUpdate = null): PromiseInterface
            {
                if ($onUpdate !== null) {
                    $onUpdate(new AgentToolResult([new TextContent('partial')]));
                }

                return $this->deferred->promise();
            }
        };

        $context = new AgentContext('', [], [$tool]);
        $userPrompt = createUserMessage('update');

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([
                new ToolCall('tool-1', 'slow-updater', []),
            ], StopReason::Done)];
        };

        $loop = new AgentLoop;
        $promise = $loop->agentLoop([$userPrompt], $context, $config, null, $streamFn);

        // The tool has been called and its $onUpdate should have fired,
        // but its returned promise is still pending.
        $updateEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolExecutionUpdateEvent));
        expect(count($updateEvents))->toBe(1);

        // Now resolve the tool and let the loop finish.
        // terminate: true so the loop knows there are no more tool calls.
        $deferred->resolve(new AgentToolResult([new TextContent('final')], null, true));

        block($promise);
    });

    it('bridges the default stream path through the Pi AI faux provider', function () {
        $registration = Faux::registerProvider();
        $registration->setResponses([
            Faux::assistantMessage([
                Faux::toolCall('echo', ['value' => 'hello'], ['id' => 'tool-1']),
            ], ['stopReason' => Pi\AI\StopReason::ToolUse]),
            Faux::assistantMessage('done'),
        ]);

        $executed = [];
        $tool = new class($executed) implements AgentTool
        {
            public function __construct(private array &$executed) {}

            public function getName(): string
            {
                return 'echo';
            }

            public function getLabel(): string
            {
                return 'Echo';
            }

            public function getDescription(): string
            {
                return 'Echo tool';
            }

            public function getParameters(): array|AiSchema
            {
                return Type::object(['value' => Type::string()]);
            }

            public function getExecutionMode(): ToolExecutionMode
            {
                return ToolExecutionMode::Parallel;
            }

            public function prepareArguments(array $args): array
            {
                return $args;
            }

            public function execute(string $toolCallId, array $params, $signal = null, $onUpdate = null): PromiseInterface
            {
                $this->executed[] = $params['value'];

                return \React\Promise\resolve(new AgentToolResult([new TextContent('echoed: '.$params['value'])]));
            }
        };

        $context = new AgentContext('You are helpful.', [], [$tool]);
        $config = new AgentLoopConfig(
            model: $registration->getModel(),
            convertToLlm: identityConverter(...),
        );

        $loop = new AgentLoop;
        $messages = block($loop->agentLoop([createUserMessage('echo hi')], $context, $config));
        $registration->unregister();

        expect($executed)->toBe(['hello']);
        expect(end($messages))->toBeInstanceOf(AssistantMessage::class);
        expect(end($messages)->content[0])->toBeInstanceOf(TextContent::class);
        expect(end($messages)->content[0]->text)->toBe('done');
    });

    it('bridges the default stream path with async faux provider factories', function () {
        $registration = Faux::registerProvider();
        $registration->setResponses([
            static fn ($context, $_options, $state) => \React\Promise\resolve(Faux::assistantMessage(count($context->messages).':'.$state['callCount'])),
        ]);

        $context = new AgentContext('You are helpful.', [], []);
        $config = new AgentLoopConfig(
            model: $registration->getModel(),
            convertToLlm: identityConverter(...),
        );

        $loop = new AgentLoop;
        $messages = block($loop->agentLoop([createUserMessage('hi')], $context, $config));
        $registration->unregister();

        expect(count($messages))->toBe(2);
        expect($messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($messages[1]->content[0])->toBeInstanceOf(TextContent::class);
        expect($messages[1]->content[0]->text)->toBe('1:1');
    });

    it('executes parallel tools concurrently', function () {
        $executionOrder = [];
        $toolA = new class($executionOrder) implements AgentTool
        {
            public function __construct(private array &$order) {}

            public function getName(): string
            {
                return 'a';
            }

            public function getLabel(): string
            {
                return 'A';
            }

            public function getDescription(): string
            {
                return 'Tool A';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function getExecutionMode(): ToolExecutionMode
            {
                return ToolExecutionMode::Parallel;
            }

            public function prepareArguments(array $args): array
            {
                return $args;
            }

            public function execute(string $toolCallId, array $params, $signal = null, $onUpdate = null): PromiseInterface
            {
                $this->order[] = 'a-start';

                return \React\Promise\resolve(new AgentToolResult([new TextContent('a')]))
                    ->then(function ($r) {
                        $this->order[] = 'a-end';

                        return $r;
                    });
            }
        };

        $toolB = new class($executionOrder) implements AgentTool
        {
            public function __construct(private array &$order) {}

            public function getName(): string
            {
                return 'b';
            }

            public function getLabel(): string
            {
                return 'B';
            }

            public function getDescription(): string
            {
                return 'Tool B';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function getExecutionMode(): ToolExecutionMode
            {
                return ToolExecutionMode::Parallel;
            }

            public function prepareArguments(array $args): array
            {
                return $args;
            }

            public function execute(string $toolCallId, array $params, $signal = null, $onUpdate = null): PromiseInterface
            {
                $this->order[] = 'b-start';

                return \React\Promise\resolve(new AgentToolResult([new TextContent('b')]))
                    ->then(function ($r) {
                        $this->order[] = 'b-end';

                        return $r;
                    });
            }
        };

        $context = new AgentContext('', [], [$toolA, $toolB]);
        $userPrompt = createUserMessage('parallel');

        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: identityConverter(...),
            toolExecution: ToolExecutionMode::Parallel,
        );

        $callIndex = 0;
        $streamFn = function () use (&$callIndex) {
            if ($callIndex === 0) {
                $callIndex++;
                yield ['type' => 'done', 'message' => createAssistantMessage([
                    new ToolCall('tc-a', 'a', []),
                    new ToolCall('tc-b', 'b', []),
                ], StopReason::Done)];
            } else {
                yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('done')])];
            }
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        // Both tools should execute and complete
        expect($executionOrder)->toContain('a-start');
        expect($executionOrder)->toContain('a-end');
        expect($executionOrder)->toContain('b-start');
        expect($executionOrder)->toContain('b-end');
    });

    it('awaits async hooks', function () {
        $context = new AgentContext('You are helpful.', [], []);
        $userPrompt = createUserMessage('Hello');

        $hookCalled = false;
        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: identityConverter(...),
            transformContext: function (array $messages) use (&$hookCalled): PromiseInterface {
                return \React\Promise\resolve($messages)->then(function ($m) use (&$hookCalled) {
                    $hookCalled = true;

                    return $m;
                });
            },
        );

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('Response')])];
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        expect($hookCalled)->toBeTrue();
    });

    it('preserves follow-up messages across outer loop iterations', function () {
        $context = new AgentContext('You are helpful.', [], []);
        $userPrompt = createUserMessage('Hello');

        $followUpDepleted = false;
        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: identityConverter(...),
            getFollowUpMessages: function () use (&$followUpDepleted): array {
                if ($followUpDepleted) {
                    return [];
                }
                $followUpDepleted = true;

                return [createUserMessage('follow-up')];
            },
        );

        $callIndex = 0;
        $streamFn = function () use (&$callIndex) {
            $callIndex++;
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent("Response {$callIndex}")])];
        };

        $loop = new AgentLoop;
        $messages = block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        expect(count($messages))->toBe(4);
        expect($messages[0]->getRole()->value)->toBe('user');
        expect($messages[1]->getRole()->value)->toBe('assistant');
        expect($messages[2]->getRole()->value)->toBe('user');
        expect($messages[3]->getRole()->value)->toBe('assistant');
    });

    it('emits agent_end exactly once on error stop reason', function () {
        $context = new AgentContext('', [], []);
        $userPrompt = createUserMessage('Hello');

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('')], StopReason::Error)];
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        $agentEndEvents = array_values(array_filter($events, fn ($e) => $e instanceof AgentEndEvent));
        expect(count($agentEndEvents))->toBe(1);
    });

    it('emits agent_end exactly once on aborted stop reason', function () {
        $context = new AgentContext('', [], []);
        $userPrompt = createUserMessage('Hello');

        $events = [];
        $config = collectEventsAgentLoopConfig(identityConverter(...), $events);

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('')], StopReason::Aborted)];
        };

        $loop = new AgentLoop;
        block($loop->agentLoop([$userPrompt], $context, $config, null, $streamFn));

        $agentEndEvents = array_values(array_filter($events, fn ($e) => $e instanceof AgentEndEvent));
        expect(count($agentEndEvents))->toBe(1);
    });
});
