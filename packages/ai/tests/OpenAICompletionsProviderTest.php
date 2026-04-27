<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\OpenAI\Completions\OpenAICompletionsProvider;
use Pi\AI\Provider;
use Pi\AI\StopReason;
use Pi\AI\UsageCost;

function createCompletionsModel(): Model
{
    return new Model(
        id: 'gpt-4o-mini',
        name: 'GPT-4o Mini',
        api: new Api(Api::OPENAI_COMPLETIONS),
        provider: new Provider(Provider::OPENAI),
        baseUrl: 'https://api.openai.com/v1',
        reasoning: false,
        input: ['text', 'image'],
        cost: new UsageCost,
        contextWindow: 128000,
        maxTokens: 16384,
    );
}

describe('OpenAI completions provider', function () {
    it('builds a stream from a fake transport and emits text events', function () {
        $provider = new OpenAICompletionsProvider(
            transport: static function (Model $model, Context $context, $options, array $params): iterable {
                expect($params['model'])->toBe('gpt-4o-mini');
                expect($params['messages'][0]['role'])->toBe('user');

                return [
                    ['id' => 'chatcmpl-123', 'choices' => [['delta' => ['content' => 'Hello '], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'choices' => [['delta' => ['content' => 'world'], 'finish_reason' => 'stop']]],
                ];
            },
        );

        $stream = $provider->stream(createCompletionsModel(), new Context(
            messages: [new UserMessage('hi', time())],
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        $terminal = $events[array_key_last($events)];
        if ($terminal instanceof ErrorEvent) {
            throw new RuntimeException('Stream error: '.$terminal->error->errorMessage);
        }
        expect($terminal)->toBeInstanceOf(DoneEvent::class);
        expect($terminal->message->responseId)->toBe('chatcmpl-123');
        expect($terminal->message->content[0]->text)->toBe('Hello world');
        expect($terminal->message->stopReason)->toBe(StopReason::Stop);
    });

    it('maps tool call streams into tool use stop reasons', function () {
        $provider = new OpenAICompletionsProvider(
            transport: static fn () => [
                ['id' => 'chatcmpl-456', 'choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'edit', 'arguments' => '']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-456', 'choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"path":"README.md"}']]]], 'finish_reason' => 'tool_calls']]],
            ],
        );

        $stream = $provider->stream(createCompletionsModel(), new Context(messages: [new UserMessage('edit', time())]));
        $message = block($stream->result());

        expect($message->stopReason)->toBe(StopReason::ToolUse);
        expect($message->content[0])->toBeInstanceOf(ToolCall::class);
        expect($message->content[0]->arguments)->toBe(['path' => 'README.md']);
    });
});
