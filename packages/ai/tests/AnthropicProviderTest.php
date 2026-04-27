<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Anthropic\AnthropicProvider;
use Pi\AI\Api;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\StopReason;
use Pi\AI\UsageCost;

function createAnthropicModel(): Model
{
    return new Model(
        id: 'claude-sonnet-4-5',
        name: 'Claude Sonnet 4.5',
        api: new Api(Api::ANTHROPIC_MESSAGES),
        provider: new Provider(Provider::ANTHROPIC),
        baseUrl: 'https://api.anthropic.com',
        reasoning: true,
        input: ['text', 'image'],
        cost: new UsageCost,
        contextWindow: 200000,
        maxTokens: 64000,
    );
}

describe('Anthropic provider', function () {
    it('builds a stream from a fake transport and emits text events', function () {
        $provider = new AnthropicProvider(
            transport: static function (Model $model, Context $context, $options, array $params): iterable {
                expect($params['model'])->toBe('claude-sonnet-4-5');
                expect($params['messages'][0]['role'])->toBe('user');

                return [
                    ['_eventType' => 'message_start', 'message' => ['id' => 'msg_123', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]]],
                    ['_eventType' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ['_eventType' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello ']],
                    ['_eventType' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'world']],
                    ['_eventType' => 'content_block_stop', 'index' => 0],
                    ['_eventType' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
                ];
            },
        );

        $stream = $provider->stream(createAnthropicModel(), new Context(
            messages: [new UserMessage('hi', time())],
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        $terminal = $events[array_key_last($events)];
        expect($terminal)->toBeInstanceOf(DoneEvent::class);
        expect($terminal->message->responseId)->toBe('msg_123');
        expect($terminal->message->content[0]->text)->toBe('Hello world');
        expect($terminal->message->stopReason)->toBe(StopReason::Stop);
    });

    it('maps tool use streams into tool use stop reasons', function () {
        $provider = new AnthropicProvider(
            transport: static fn () => [
                ['_eventType' => 'message_start', 'message' => ['id' => 'msg_456', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]]],
                ['_eventType' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'edit', 'input' => []]],
                ['_eventType' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"path":"README.md"}']],
                ['_eventType' => 'content_block_stop', 'index' => 0],
                ['_eventType' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
            ],
        );

        $stream = $provider->stream(createAnthropicModel(), new Context(messages: [new UserMessage('edit', time())]));
        $message = block($stream->result());

        expect($message->stopReason)->toBe(StopReason::ToolUse);
        expect($message->content[0])->toBeInstanceOf(ToolCall::class);
        expect($message->content[0]->arguments)->toBe(['path' => 'README.md']);
    });
});
