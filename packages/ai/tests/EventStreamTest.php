<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\Content\TextContent;
use Pi\AI\Event\AssistantMessageEventType;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Event\StartEvent;
use Pi\AI\Event\TextDeltaEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Provider;
use Pi\AI\StopReason;
use Pi\AI\Usage;

function createEventStreamAssistantMessage(string $text, StopReason $stopReason = StopReason::Stop, ?string $errorMessage = null): AssistantMessage
{
    return new AssistantMessage(
        content: [new TextContent($text)],
        api: new Api(Api::OPENAI_RESPONSES),
        provider: new Provider(Provider::OPENAI),
        model: 'gpt-5-mini',
        usage: Usage::zero(),
        stopReason: $stopReason,
        timestamp: 123456789,
        errorMessage: $errorMessage,
    );
}

describe('AssistantMessageEventStream', function () {
    it('resolves queued events in order and resolves the done message', function () {
        $partial = createEventStreamAssistantMessage('Hello');
        $final = createEventStreamAssistantMessage('Hello world', StopReason::Stop);

        $stream = new AssistantMessageEventStream;
        $stream->push(new StartEvent($partial));
        $stream->push(new TextDeltaEvent(0, ' world', $partial));
        $stream->push(new DoneEvent(StopReason::Stop, $final));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        expect(array_map(fn ($event) => $event->getType(), $events))->toBe([
            AssistantMessageEventType::Start,
            AssistantMessageEventType::TextDelta,
            AssistantMessageEventType::Done,
        ]);
        expect(block($stream->result()))->toBe($final);
    });

    it('resolves the final assistant message from terminal error events', function () {
        $error = createEventStreamAssistantMessage('Partial', StopReason::Error, 'Request failed');

        $stream = new AssistantMessageEventStream;
        $stream->push(new ErrorEvent(StopReason::Error, $error));

        expect(block($stream->next()))->toBeInstanceOf(ErrorEvent::class);
        expect(block($stream->next()))->toBeNull();
        expect(block($stream->result()))->toBe($error);
        expect(block($stream->result())->errorMessage)->toBe('Request failed');
    });

    it('resolves a pending next promise when a new event is pushed', function () {
        $partial = createEventStreamAssistantMessage('Hello');

        $stream = new AssistantMessageEventStream;
        $pending = $stream->next();

        $stream->push(new StartEvent($partial));
        $stream->end();

        $event = block($pending);

        expect($event)->toBeInstanceOf(StartEvent::class);
        expect($event?->getType())->toBe(AssistantMessageEventType::Start);
        expect(block($stream->next()))->toBeNull();
    });

    it('rejects invalid stop reasons for done events', function () {
        expect(fn () => new DoneEvent(StopReason::Error, createEventStreamAssistantMessage('Nope', StopReason::Error)))
            ->toThrow(InvalidArgumentException::class, 'DoneEvent requires a successful stop reason.');
    });

    it('rejects invalid stop reasons for error events', function () {
        expect(fn () => new ErrorEvent(StopReason::ToolUse, createEventStreamAssistantMessage('Nope', StopReason::Error)))
            ->toThrow(InvalidArgumentException::class, 'ErrorEvent requires an error stop reason.');
    });
});
