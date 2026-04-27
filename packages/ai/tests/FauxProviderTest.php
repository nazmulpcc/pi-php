<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\AssistantMessageEventStream;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Event\ToolCallDeltaEvent;
use Pi\AI\Faux;
use Pi\AI\FauxProviderRegistration;
use Pi\AI\Message\UserMessage;
use Pi\AI\SimpleCancellationToken;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;

use function Pi\AI\complete;
use function Pi\AI\stream;

/** @var array<int, FauxProviderRegistration> $registrations */
$registrations = [];

afterEach(function () use (&$registrations) {
    foreach ($registrations as $registration) {
        $registration->unregister();
    }

    $registrations = [];
});

function collectFauxEvents(AssistantMessageEventStream $stream): array
{
    $events = [];

    while (($event = block($stream->next())) !== null) {
        $events[] = $event;
    }

    return $events;
}

describe('faux provider', function () use (&$registrations) {
    it('registers a custom provider and estimates usage', function () use (&$registrations) {
        $registration = Faux::registerProvider();
        $registrations[] = $registration;
        $registration->setResponses([Faux::assistantMessage('hello world')]);

        $response = block(complete($registration->getModel(), new Context(
            messages: [new UserMessage('hi there', time())],
            systemPrompt: 'Be concise.',
        )));

        expect($response->content[0]->text)->toBe('hello world');
        expect($response->usage->input)->toBeGreaterThan(0);
        expect($response->usage->output)->toBeGreaterThan(0);
        expect($response->usage->totalTokens)->toBe($response->usage->input + $response->usage->output);
        expect($registration->state['callCount'])->toBe(1);
    });

    it('supports helper blocks for text thinking and tool calls', function () use (&$registrations) {
        $registration = Faux::registerProvider();
        $registrations[] = $registration;
        $registration->setResponses([
            Faux::assistantMessage([Faux::thinking('think'), Faux::toolCall('echo', ['text' => 'hi']), Faux::text('done')], [
                'stopReason' => StopReason::ToolUse,
            ]),
        ]);

        $response = block(complete($registration->getModel(), new Context(messages: [new UserMessage('hi', time())])));

        expect($response->content[0])->toBeInstanceOf(ThinkingContent::class);
        expect($response->content[1])->toBeInstanceOf(ToolCall::class);
        expect($response->content[2])->toBeInstanceOf(TextContent::class);
        expect($response->stopReason)->toBe(StopReason::ToolUse);
    });

    it('supports multiple models and model-aware factories', function () use (&$registrations) {
        $registration = Faux::registerProvider([
            'models' => [
                ['id' => 'faux-fast', 'name' => 'Faux Fast', 'reasoning' => false],
                ['id' => 'faux-thinker', 'name' => 'Faux Thinker', 'reasoning' => true],
            ],
        ]);
        $registrations[] = $registration;
        $registration->setResponses([
            static fn ($_context, $_options, $_state, $model) => Faux::assistantMessage("{$model->id}:".($model->reasoning ? 'true' : 'false')),
            static fn ($_context, $_options, $_state, $model) => Faux::assistantMessage("{$model->id}:".($model->reasoning ? 'true' : 'false')),
        ]);

        expect(array_map(static fn ($model) => $model->id, $registration->models))->toBe(['faux-fast', 'faux-thinker']);
        expect($registration->getModel('faux-fast')->reasoning)->toBeFalse();
        expect($registration->getModel('faux-thinker')->reasoning)->toBeTrue();

        $fast = block(complete($registration->getModel('faux-fast'), new Context(messages: [new UserMessage('hi', time())])));
        $thinker = block(complete($registration->getModel('faux-thinker'), new Context(messages: [new UserMessage('hi', time())])));

        expect($fast->content[0]->text)->toBe('faux-fast:false');
        expect($thinker->content[0]->text)->toBe('faux-thinker:true');
    });

    it('rewrites api provider and model on returned messages', function () use (&$registrations) {
        $registration = Faux::registerProvider([
            'api' => 'faux:test',
            'provider' => 'faux-provider',
            'models' => [['id' => 'faux-model']],
        ]);
        $registrations[] = $registration;
        $registration->setResponses([Faux::assistantMessage('hello')]);

        $response = block(complete($registration->getModel(), new Context(messages: [new UserMessage('hi', time())])));

        expect($response->api->value)->toBe('faux:test');
        expect($response->provider->value)->toBe('faux-provider');
        expect($response->model)->toBe('faux-model');
    });

    it('consumes queued responses in order and errors when exhausted', function () use (&$registrations) {
        $registration = Faux::registerProvider();
        $registrations[] = $registration;
        $registration->setResponses([Faux::assistantMessage('first'), Faux::assistantMessage('second')]);

        $context = new Context(messages: [new UserMessage('hi', time())]);
        $first = block(complete($registration->getModel(), $context));
        $second = block(complete($registration->getModel(), $context));
        $exhausted = block(complete($registration->getModel(), $context));

        expect($first->content[0]->text)->toBe('first');
        expect($second->content[0]->text)->toBe('second');
        expect($exhausted->stopReason)->toBe(StopReason::Error);
        expect($exhausted->errorMessage)->toBe('No more faux responses queued');
        expect($registration->getPendingResponseCount())->toBe(0);
        expect($registration->state['callCount'])->toBe(3);
    });

    it('can replace and append queued responses', function () use (&$registrations) {
        $registration = Faux::registerProvider();
        $registrations[] = $registration;
        $registration->setResponses([Faux::assistantMessage('first')]);

        $context = new Context(messages: [new UserMessage('hi', time())]);
        expect(block(complete($registration->getModel(), $context))->content[0]->text)->toBe('first');

        $registration->setResponses([Faux::assistantMessage('second')]);
        expect($registration->getPendingResponseCount())->toBe(1);
        expect(block(complete($registration->getModel(), $context))->content[0]->text)->toBe('second');

        $registration->appendResponses([Faux::assistantMessage('third'), Faux::assistantMessage('fourth')]);
        expect(block(complete($registration->getModel(), $context))->content[0]->text)->toBe('third');
        expect(block(complete($registration->getModel(), $context))->content[0]->text)->toBe('fourth');
    });

    it('supports async response factories and prompt caching', function () use (&$registrations) {
        $registration = Faux::registerProvider();
        $registrations[] = $registration;
        $registration->setResponses([
            static fn ($context, $_options, $state) => React\Promise\resolve(Faux::assistantMessage(count($context->messages).':'.$state['callCount'])),
            static fn ($_context, $_options, $_state) => React\Promise\resolve(Faux::assistantMessage('cached')),
        ]);

        $context = new Context(messages: [new UserMessage('hi', time())]);
        $first = block(complete($registration->getModel(), $context, new StreamOptions(sessionId: 'session-1')));
        $context = new Context(messages: [new UserMessage('hi', time()), $first, new UserMessage('follow up', time() + 1)]);
        $second = block(complete($registration->getModel(), $context, new StreamOptions(sessionId: 'session-1')));

        expect($first->content[0]->text)->toBe('1:1');
        expect($first->usage->cacheWrite)->toBeGreaterThan(0);
        expect($second->usage->cacheRead)->toBeGreaterThan(0);
    });

    it('streams text thinking and partial tool call deltas', function () use (&$registrations) {
        $registration = Faux::registerProvider(['tokenSize' => ['min' => 1, 'max' => 1]]);
        $registrations[] = $registration;
        $registration->setResponses([
            Faux::assistantMessage([
                Faux::thinking('go'),
                Faux::text('ok'),
                Faux::toolCall('echo', ['text' => 'hi', 'count' => 12], ['id' => 'tool-1']),
            ], ['stopReason' => StopReason::ToolUse]),
        ]);

        $events = collectFauxEvents(stream($registration->getModel(), new Context(messages: [new UserMessage('hi', time())])));
        $toolCallDeltas = array_values(array_map(static fn ($event) => $event->delta, array_filter($events, static fn ($event) => $event instanceof ToolCallDeltaEvent)));
        $eventTypes = array_map(static fn ($event) => $event->getType()->value, $events);

        expect(array_slice($eventTypes, 0, 8))->toBe([
            'start',
            'thinking_start',
            'thinking_delta',
            'thinking_end',
            'text_start',
            'text_delta',
            'text_end',
            'toolcall_start',
        ]);
        expect(count($toolCallDeltas))->toBeGreaterThan(1);
        expect($eventTypes[array_key_last($eventTypes) - 1])->toBe('toolcall_end');
        expect($eventTypes[array_key_last($eventTypes)])->toBe('done');
        expect(json_decode(implode('', $toolCallDeltas), true))->toBe(['text' => 'hi', 'count' => 12]);
    });

    it('emits explicit assistant errors as terminal error events', function () use (&$registrations) {
        $registration = Faux::registerProvider();
        $registrations[] = $registration;
        $registration->setResponses([
            Faux::assistantMessage('partial', ['stopReason' => StopReason::Error, 'errorMessage' => 'upstream failed']),
        ]);

        $events = collectFauxEvents(stream($registration->getModel(), new Context(messages: [new UserMessage('hi', time())])));
        $terminal = $events[array_key_last($events)];

        expect($terminal)->toBeInstanceOf(ErrorEvent::class);
        expect($terminal->reason)->toBe(StopReason::Error);
        expect($terminal->error->errorMessage)->toBe('upstream failed');
    });

    it('supports aborting before the first chunk', function () use (&$registrations) {
        $registration = Faux::registerProvider();
        $registrations[] = $registration;
        $registration->setResponses([Faux::assistantMessage('abcdefghijklmnopqrstuvwxyz')]);

        $token = new SimpleCancellationToken;
        $token->cancel();
        $events = collectFauxEvents(stream($registration->getModel(), new Context(messages: [new UserMessage('hi', time())]), new StreamOptions(signal: $token)));

        expect($events)->toHaveCount(1);
        expect($events[0])->toBeInstanceOf(ErrorEvent::class);
        expect($events[0]->reason)->toBe(StopReason::Aborted);
    });
});
