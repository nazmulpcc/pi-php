<?php

declare(strict_types=1);

use Pi\Agent\AgentContext;
use Pi\Agent\AgentLoop;
use Pi\Agent\AgentLoopConfig;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\Event\AgentEndEvent;
use Pi\Agent\Event\ToolExecutionEndEvent;
use Pi\Agent\Event\ToolExecutionStartEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\StopReason;
use Pi\Agent\Tool\AgentTool;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;

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

describe('AgentLoop', function () {
    it('emits events for a simple response', function () {
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
        $generator = $loop->agentLoop([$userPrompt], $context, $config, null, $streamFn);

        $events = [];
        foreach ($generator as $event) {
            $events[] = $event;
        }

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
        $generator = $loop->agentLoop([$userPrompt], $context, $config, null, $streamFn);

        foreach ($generator as $event) {
        }
        $messages = $generator->getReturn();

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

            public function execute(string $toolCallId, array $params, $signal = null, $onUpdate = null): AgentToolResult
            {
                $this->executed[] = $params['value'];

                return new AgentToolResult([new TextContent("echoed: {$params['value']}")]);
            }
        };

        $context = new AgentContext('', [], [$tool]);
        $userPrompt = createUserMessage('echo something');

        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: identityConverter(...),
        );

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
        $generator = $loop->agentLoop([$userPrompt], $context, $config, null, $streamFn);

        $events = [];
        foreach ($generator as $event) {
            $events[] = $event;
        }

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
        $generator = $loop->agentLoop([$userPrompt], $context, $config, null, $streamFn);
        foreach ($generator as $event) {
        }

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
        $generator = $loop->agentLoop([$userPrompt], $context, $config, null, $streamFn);
        foreach ($generator as $event) {
        }

        expect(count($convertedMessages))->toBe(1);
        expect($convertedMessages[0]->getRole()->value)->toBe('user');
    });

    it('handles error stop reason', function () {
        $context = new AgentContext('', [], []);
        $userPrompt = createUserMessage('Hello');

        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: identityConverter(...),
        );

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('')], StopReason::Error)];
        };

        $loop = new AgentLoop;
        $generator = $loop->agentLoop([$userPrompt], $context, $config, null, $streamFn);

        $events = [];
        foreach ($generator as $event) {
            $events[] = $event;
        }

        $lastEvent = end($events);
        expect($lastEvent)->toBeInstanceOf(AgentEndEvent::class);
    });

    it('continues from existing context', function () {
        $context = new AgentContext('', [
            createUserMessage('Hello'),
            createAssistantMessage([new TextContent('Hi!')]),
            createUserMessage('How are you?'),
        ], []);

        $config = new AgentLoopConfig(
            model: null,
            convertToLlm: identityConverter(...),
        );

        $streamFn = function () {
            yield ['type' => 'done', 'message' => createAssistantMessage([new TextContent('I am fine')])];
        };

        $loop = new AgentLoop;
        $generator = $loop->agentLoopContinue($context, $config, null, $streamFn);

        $events = [];
        foreach ($generator as $event) {
            $events[] = $event;
        }

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
            $generator = $loop->agentLoopContinue($context, $config);
            foreach ($generator as $event) {
            }
        })->toThrow(RuntimeException::class, 'Cannot continue from message role: assistant');
    });
});
