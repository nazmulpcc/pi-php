<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';
require_once aiPackageRoot('src/OpenAI/OpenAIResponsesOptions.php');
require_once aiPackageRoot('src/OpenAI/SimpleOptions.php');
require_once aiPackageRoot('src/OpenAI/OpenAIResponsesShared.php');
require_once aiPackageRoot('src/OpenAI/OpenAIResponsesProvider.php');

use Pi\AI\Api;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\OpenAI\OpenAIResponsesProvider;
use Pi\AI\Provider;
use Pi\AI\Schema\Type;
use Pi\AI\StopReason;
use Pi\AI\Tool;
use Pi\AI\UsageCost;

function createProviderModel(): Model
{
    return new Model(
        id: 'gpt-5-mini',
        name: 'GPT-5 Mini',
        api: new Api(Api::OPENAI_RESPONSES),
        provider: new Provider(Provider::OPENAI),
        baseUrl: 'https://api.openai.com/v1',
        reasoning: true,
        input: ['text'],
        cost: new UsageCost,
        contextWindow: 400000,
        maxTokens: 128000,
    );
}

describe('OpenAI responses provider', function () {
    it('builds a stream from a fake transport and preserves response id', function () {
        $provider = new OpenAIResponsesProvider(
            transport: static function (Model $model, Context $context, $options, array $params): iterable {
                expect($params['model'])->toBe('gpt-5-mini');
                expect($params['input'][0]['role'])->toBe('developer');
                expect($params['tools'][0]['name'])->toBe('echo');

                return [
                    ['type' => 'response.created', 'response' => ['id' => 'resp_1']],
                    ['type' => 'response.output_item.added', 'item' => ['type' => 'message']],
                    ['type' => 'response.output_text.delta', 'delta' => 'hello'],
                    ['type' => 'response.output_item.done', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => [['type' => 'output_text', 'text' => 'hello']]]],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed', 'usage' => ['input_tokens' => 20, 'output_tokens' => 5, 'total_tokens' => 25, 'input_tokens_details' => ['cached_tokens' => 0]]]],
                ];
            },
        );

        $stream = $provider->stream(createProviderModel(), new Context(
            messages: [new UserMessage('hi', time())],
            systemPrompt: 'Be concise.',
            tools: [new Tool('echo', 'Echo text', Type::object(['text' => Type::string()]))],
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        $terminal = $events[array_key_last($events)];
        expect($terminal)->toBeInstanceOf(DoneEvent::class);
        expect($terminal->message->responseId)->toBe('resp_1');
        expect($terminal->message->content[0]->text)->toBe('hello');
    });

    it('maps function call streams into tool use stop reasons', function () {
        $provider = new OpenAIResponsesProvider(
            transport: static fn () => [
                ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_test', 'call_id' => 'call_test', 'name' => 'edit', 'arguments' => '']],
                ['type' => 'response.function_call_arguments.delta', 'delta' => '{"path":"README.md"}'],
                ['type' => 'response.output_item.done', 'item' => ['type' => 'function_call', 'id' => 'fc_test', 'call_id' => 'call_test', 'name' => 'edit', 'arguments' => '{"path":"README.md"}']],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_2', 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 1, 'total_tokens' => 11, 'input_tokens_details' => ['cached_tokens' => 0]]]],
            ],
        );

        $stream = $provider->stream(createProviderModel(), new Context(messages: [new UserMessage('edit', time())]));
        $message = block($stream->result());

        expect($message->stopReason)->toBe(StopReason::ToolUse);
        expect($message->content[0])->toBeInstanceOf(ToolCall::class);
        expect($message->content[0]->arguments)->toBe(['path' => 'README.md']);
    });
});
