<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\Agent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Event\AgentEvent;
use Pi\Agent\Event\MessageUpdateEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\MutableAgentState;
use Pi\Agent\StopReason;
use Pi\Agent\ThinkingLevel;
use Pi\Agent\ToolExecutionMode;
use React\Promise\PromiseInterface;

describe('Agent', function () {
    it('creates with default state', function () {
        $agent = new Agent;

        expect($agent->getState())->not->toBeNull();
        expect($agent->getState()->getSystemPrompt())->toBe('');
        expect($agent->getState()->getThinkingLevel())->toBe(ThinkingLevel::Off);
        expect($agent->getState()->getTools())->toBe([]);
        expect($agent->getState()->getMessages())->toBe([]);
        expect($agent->getState()->isStreaming())->toBeFalse();
        expect($agent->getState()->getStreamingMessage())->toBeNull();
        expect($agent->getState()->getPendingToolCalls())->toBe([]);
        expect($agent->getState()->getErrorMessage())->toBeNull();
    });

    it('creates with custom initial state', function () {
        $state = new MutableAgentState('You are helpful.', ThinkingLevel::Low);
        $agent = new Agent($state);

        expect($agent->getState()->getSystemPrompt())->toBe('You are helpful.');
        expect($agent->getState()->getThinkingLevel())->toBe(ThinkingLevel::Low);
    });

    it('subscribes and unsubscribes from events', function () {
        $agent = new Agent;

        $eventCount = 0;
        $unsubscribe = $agent->subscribe(function (AgentEvent $event, $token) use (&$eventCount) {
            $eventCount++;
        });

        expect($eventCount)->toBe(0);

        $unsubscribe();
        expect($eventCount)->toBe(0);
    });

    it('queues steering messages', function () {
        $agent = new Agent;
        $message = new UserMessage([new TextContent('steer')], time() * 1000);

        $agent->steer($message);
        expect($agent->hasQueuedMessages())->toBeTrue();
    });

    it('queues follow-up messages', function () {
        $agent = new Agent;
        $message = new UserMessage([new TextContent('follow')], time() * 1000);

        $agent->followUp($message);
        expect($agent->hasQueuedMessages())->toBeTrue();
    });

    it('clears queues', function () {
        $agent = new Agent;
        $agent->steer(new UserMessage([new TextContent('steer')], time() * 1000));
        $agent->followUp(new UserMessage([new TextContent('follow')], time() * 1000));

        expect($agent->hasQueuedMessages())->toBeTrue();

        $agent->clearAllQueues();
        expect($agent->hasQueuedMessages())->toBeFalse();
    });

    it('sets steering mode', function () {
        $agent = new Agent;
        expect($agent->getSteeringMode())->toBe('one-at-a-time');

        $agent->setSteeringMode('all');
        expect($agent->getSteeringMode())->toBe('all');
    });

    it('sets follow-up mode', function () {
        $agent = new Agent;
        expect($agent->getFollowUpMode())->toBe('one-at-a-time');

        $agent->setFollowUpMode('all');
        expect($agent->getFollowUpMode())->toBe('all');
    });

    it('resets state', function () {
        $state = new MutableAgentState;
        $state->setMessages([new UserMessage([new TextContent('test')], time() * 1000)]);
        $state->setIsStreaming(true);
        $state->setErrorMessage('error');

        $agent = new Agent($state);
        $agent->steer(new UserMessage([new TextContent('queued')], time() * 1000));

        $agent->reset();

        expect($agent->getState()->getMessages())->toBe([]);
        expect($agent->getState()->isStreaming())->toBeFalse();
        expect($agent->getState()->getErrorMessage())->toBeNull();
        expect($agent->hasQueuedMessages())->toBeFalse();
    });

    it('tracks running state during prompt', function () {
        $streamFn = function () {
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $agent = new Agent(streamFn: $streamFn);

        expect($agent->isRunning())->toBeFalse();
        $agent->prompt('hello');
        expect($agent->isRunning())->toBeFalse();
    });

    it('throws when continuing with no messages', function () {
        $agent = new Agent;

        expect(fn () => $agent->continue())
            ->toThrow(RuntimeException::class, 'No messages to continue from');
    });

    it('throws when continuing from assistant message without queued messages', function () {
        $state = new MutableAgentState;
        $state->setMessages([
            new UserMessage([new TextContent('hello')], time() * 1000),
            new AssistantMessage(
                [new TextContent('hi')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            ),
        ]);

        $agent = new Agent($state);

        expect(fn () => $agent->continue())
            ->toThrow(RuntimeException::class, 'Cannot continue from message role: assistant');
    });

    it('sets tool execution mode', function () {
        $agent = new Agent;
        expect($agent->toolExecution)->toBe(ToolExecutionMode::Parallel);

        $agent->toolExecution = ToolExecutionMode::Sequential;
        expect($agent->toolExecution)->toBe(ToolExecutionMode::Sequential);
    });

    it('normalizes string prompt to user message', function () {
        $streamFn = function () {
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $agent = new Agent(streamFn: $streamFn);
        $agent->prompt('Hello world');

        $messages = $agent->getState()->getMessages();
        expect(count($messages))->toBe(2);
        expect($messages[0])->toBeInstanceOf(UserMessage::class);
    });

    it('accepts array of messages as prompt', function () {
        $streamFn = function () {
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $agent = new Agent(streamFn: $streamFn);
        $messages = [
            new UserMessage([new TextContent('msg1')], time() * 1000),
            new UserMessage([new TextContent('msg2')], time() * 1000),
        ];

        $agent->prompt($messages);

        expect(count($agent->getState()->getMessages()))->toBe(3);
    });

    it('returns a promise from prompt', function () {
        $streamFn = function () {
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $agent = new Agent(streamFn: $streamFn);
        $promise = $agent->prompt('hello');

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('returns a promise from continue', function () {
        $state = new MutableAgentState;
        $state->setMessages([
            new UserMessage([new TextContent('hello')], time() * 1000),
        ]);

        $streamFn = function () {
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $agent = new Agent($state, streamFn: $streamFn);
        $promise = $agent->continue();

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('waits for idle when not running', function () {
        $agent = new Agent;
        $promise = $agent->waitForIdle();

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
        block($promise);
        expect($agent->isRunning())->toBeFalse();
    });

    it('awaits async listeners', function () {
        $listenerOrder = [];
        $streamFn = function () {
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $agent = new Agent(streamFn: $streamFn);
        $agent->subscribe(function (AgentEvent $event) use (&$listenerOrder) {
            $listenerOrder[] = 'sync';
        });
        $agent->subscribe(function (AgentEvent $event) use (&$listenerOrder) {
            return \React\Promise\resolve(null)->then(function () use (&$listenerOrder) {
                $listenerOrder[] = 'async';
            });
        });

        block($agent->prompt('hello'));

        // Both listeners are called for every event; verify async listeners are
        // awaited after sync listeners for each event pair.
        expect(count($listenerOrder))->toBeGreaterThan(0);
        foreach (array_chunk($listenerOrder, 2) as $pair) {
            expect($pair)->toBe(['sync', 'async']);
        }
    });

    it('includes raw event in message update', function () {
        $rawEvent = ['type' => 'text_delta', 'delta' => 'ok'];
        $streamFn = function () use ($rawEvent) {
            yield ['type' => 'start', 'partial' => new AssistantMessage(
                [new TextContent('')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
            yield ['type' => 'text_delta', 'partial' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            ), 'raw' => $rawEvent];
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $updateEvents = [];
        $agent = new Agent(streamFn: $streamFn);
        $agent->subscribe(function (AgentEvent $event) use (&$updateEvents) {
            if ($event instanceof MessageUpdateEvent) {
                $updateEvents[] = $event;
            }

            return \React\Promise\resolve(null);
        });

        block($agent->prompt('hello'));

        expect(count($updateEvents))->toBe(1);
        expect($updateEvents[0]->rawEvent)->toBe($rawEvent);
    });

    it('agent_end is last event and run is idle after listeners settle', function () {
        $streamFn = function () {
            yield ['type' => 'done', 'message' => new AssistantMessage(
                [new TextContent('ok')],
                'api',
                'provider',
                'model',
                StopReason::Done,
                time() * 1000,
            )];
        };

        $eventTypes = [];
        $agent = new Agent(streamFn: $streamFn);
        $agent->subscribe(function (AgentEvent $event) use (&$eventTypes) {
            $eventTypes[] = $event->getType()->value;

            return \React\Promise\resolve(null);
        });

        block($agent->prompt('hello'));

        expect(end($eventTypes))->toBe('agent_end');
        expect($agent->isRunning())->toBeFalse();
    });

    it('awaits async listeners on failure path before run is idle', function () {
        $listenerSettled = false;
        $agent = new Agent(streamFn: function () {
            throw new RuntimeException('Stream exploded');
        });
        $agent->subscribe(function (AgentEvent $event) use (&$listenerSettled) {
            return \React\Promise\resolve(null)->then(function () use (&$listenerSettled) {
                $listenerSettled = true;
            });
        });

        block($agent->prompt('hello'));

        expect($listenerSettled)->toBeTrue();
        expect($agent->isRunning())->toBeFalse();
    });
});
