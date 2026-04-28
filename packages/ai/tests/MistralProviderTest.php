<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\TextDeltaEvent;
use Pi\AI\Event\ThinkingDeltaEvent;
use Pi\AI\Event\ToolCallDeltaEvent;
use Pi\AI\Message\UserMessage;
use Pi\AI\Mistral\MistralOptions;
use Pi\AI\Mistral\MistralProvider;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\ThinkingLevel;
use Pi\AI\Tool;
use Pi\AI\UsageCost;

function hasMistralEvent(array $events, string $class): bool
{
    foreach ($events as $event) {
        if ($event instanceof $class) {
            return true;
        }
    }

    return false;
}

function createMistralModel(string $id = 'mistral-large-2411', bool $reasoning = true): Model
{
    return new Model(
        id: $id,
        name: 'Mistral',
        api: new Api(Api::MISTRAL_CONVERSATIONS),
        provider: new Provider(Provider::MISTRAL),
        baseUrl: 'https://api.mistral.ai/v1',
        reasoning: $reasoning,
        input: ['text'],
        cost: new UsageCost,
        contextWindow: 128000,
        maxTokens: 32000,
    );
}

describe('Mistral provider', function () {
    it('builds a stream from a fake transport and emits text, thinking, and tool events', function () {
        $provider = new MistralProvider(
            transport: static function (Model $model, Context $context, MistralOptions $options, array $params): iterable {
                expect($params['model'])->toBe('mistral-large-2411');
                expect($params['messages'][0]['role'])->toBe('user');
                expect($params['messages'][0]['content'])->toBe('hi');
                expect($params['tools'][0]['function']['name'])->toBe('edit');
                expect($params['tools'][0]['function']['strict'])->toBeFalse();
                if ($model->id === '' && $context->messages === [] && $options->promptMode === '') {
                    // no-op
                }

                return [
                    ['id' => 'chatcmpl-123', 'choices' => [['delta' => ['content' => 'Hello '], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'choices' => [['delta' => ['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'ponder']]]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'edit', 'arguments' => '']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"path":"README.md"}']]]], 'finish_reason' => 'tool_calls']]],
                ];
            },
        );

        $stream = $provider->stream(createMistralModel(), new Context(
            messages: [new UserMessage('hi', time())],
            tools: [new Tool('edit', 'Edit a file', ['type' => 'object'])],
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        expect(hasMistralEvent($events, TextDeltaEvent::class))->toBeTrue();
        expect(hasMistralEvent($events, ThinkingDeltaEvent::class))->toBeTrue();
        expect(hasMistralEvent($events, ToolCallDeltaEvent::class))->toBeTrue();

        $terminal = $events[array_key_last($events)];
        expect($terminal)->toBeInstanceOf(DoneEvent::class);
        expect($terminal->message->responseId)->toBe('chatcmpl-123');
        expect($terminal->message->content[0]->text)->toBe('Hello ');
        expect($terminal->message->content[1])->toBeInstanceOf(ThinkingContent::class);
        expect($terminal->message->content[1]->thinking)->toBe('ponder');
        expect($terminal->message->content[2])->toBeInstanceOf(ToolCall::class);
        expect($terminal->message->content[2]->arguments)->toBe(['path' => 'README.md']);
        expect($terminal->message->stopReason)->toBe(StopReason::ToolUse);
    });

    it('maps streamSimple reasoning to prompt mode for larger models', function () {
        $called = false;
        $provider = new MistralProvider(
            transport: static function (Model $model, Context $context, MistralOptions $options, array $params) use (&$called): iterable {
                $called = true;
                expect($model->id)->toBe('mistral-large-2411');
                expect($context->messages)->toHaveCount(1);
                expect($options->promptMode)->toBe('reasoning');
                expect($options->reasoningEffort)->toBeNull();
                expect($params['messages'][0]['role'])->toBe('user');

                return [
                    ['id' => 'chatcmpl-456', 'choices' => [['delta' => ['content' => 'OK'], 'finish_reason' => 'stop']]],
                ];
            },
        );

        $stream = $provider->streamSimple(createMistralModel('mistral-large-2411', true), new Context(
            messages: [new UserMessage('hi', time())],
        ), new SimpleStreamOptions(reasoning: ThinkingLevel::High));

        block($stream->result());

        expect($called)->toBeTrue();
    });

    it('maps streamSimple reasoning to reasoningEffort for small models', function () {
        $called = false;
        $provider = new MistralProvider(
            transport: static function (Model $model, Context $context, MistralOptions $options, array $params) use (&$called): iterable {
                $called = true;
                expect($model->id)->toBe('mistral-small-2603');
                expect($context->messages)->toHaveCount(1);
                expect($options->promptMode)->toBeNull();
                expect($options->reasoningEffort)->toBe('high');
                expect($params['messages'][0]['role'])->toBe('user');

                return [
                    ['id' => 'chatcmpl-789', 'choices' => [['delta' => ['content' => 'OK'], 'finish_reason' => 'stop']]],
                ];
            },
        );

        $stream = $provider->streamSimple(createMistralModel('mistral-small-2603', true), new Context(
            messages: [new UserMessage('hi', time())],
        ), new SimpleStreamOptions(reasoning: ThinkingLevel::High));

        block($stream->result());

        expect($called)->toBeTrue();
    });
});
