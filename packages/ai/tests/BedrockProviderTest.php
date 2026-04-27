<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\Bedrock\BedrockOptions;
use Pi\AI\Bedrock\BedrockProvider;
use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\Schema\Type;
use Pi\AI\StopReason;
use Pi\AI\ThinkingLevel;
use Pi\AI\Tool;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

function createBedrockModel(): Model
{
    return new Model(
        id: 'anthropic.claude-3-7-sonnet-v1:0',
        name: 'Claude 3.7 Sonnet',
        api: new Api(Api::BEDROCK_CONVERSE_STREAM),
        provider: new Provider(Provider::AMAZON_BEDROCK),
        baseUrl: 'https://bedrock-runtime.us-east-1.amazonaws.com',
        reasoning: true,
        input: ['text', 'image'],
        cost: new UsageCost,
        contextWindow: 200000,
        maxTokens: 64000,
    );
}

describe('Bedrock provider', function () {
    it('converts messages and tools to Bedrock converse format', function () {
        $provider = new BedrockProvider(
            transport: static function (...$args): iterable {
                [$model, $_context, $_options, $params] = $args;
                expect($model->id)->toBe('anthropic.claude-3-7-sonnet-v1:0');
                expect($_context)->toBeInstanceOf(Context::class);
                expect($_options)->toBeInstanceOf(BedrockOptions::class);
                expect($params['modelId'])->toBe('anthropic.claude-3-7-sonnet-v1:0');
                expect($params['system'][0]['text'])->toBe('system prompt');
                expect($params['toolConfig']['tools'][0]['toolSpec']['name'])->toBe('write_file');
                expect($params['toolConfig']['toolChoice'])->toBe(['tool' => ['name' => 'write_file']]);

                expect($params['messages'][0])->toBe([
                    'role' => 'user',
                    'content' => [
                        ['text' => 'Look'],
                        ['image' => ['format' => 'png', 'source' => ['bytes' => 'aW1hZ2U=']]],
                    ],
                ]);

                expect($params['messages'][1]['role'])->toBe('assistant');
                expect($params['messages'][1]['content'][0])->toBe(['text' => 'Working']);
                expect($params['messages'][1]['content'][1])->toBe([
                    'reasoningContent' => [
                        'reasoningText' => ['text' => 'Need a tool', 'signature' => 'sig-1'],
                    ],
                ]);
                expect($params['messages'][1]['content'][2]['toolUse']['toolUseId'])->toBe('bad_id_1');

                expect($params['messages'][2])->toBe([
                    'role' => 'user',
                    'content' => [
                        ['toolResult' => [
                            'toolUseId' => 'bad_id_1',
                            'content' => [['text' => 'ok']],
                            'status' => 'success',
                        ]],
                        ['toolResult' => [
                            'toolUseId' => 'next',
                            'content' => [['text' => 'fail']],
                            'status' => 'error',
                        ]],
                    ],
                ]);

                expect($params['additionalModelRequestFields']['thinking']['type'])->toBe('enabled');
                expect($params['additionalModelRequestFields']['thinking']['budget_tokens'])->toBe(2048);
                expect($params['requestMetadata'])->toBe(['trace' => 'abc']);

                return [
                    ['messageStart' => ['role' => 'assistant']],
                    ['messageStop' => ['stopReason' => 'stop_sequence']],
                ];
            },
        );

        $assistant = new AssistantMessage(
            content: [
                new TextContent('Working'),
                new ThinkingContent('Need a tool', 'sig-1'),
                new ToolCall('bad_id_1', 'write_file', ['path' => 'README.md']),
            ],
            api: new Api(Api::BEDROCK_CONVERSE_STREAM),
            provider: new Provider(Provider::AMAZON_BEDROCK),
            model: 'anthropic.claude-3-7-sonnet-v1:0',
            usage: new Usage,
            stopReason: StopReason::ToolUse,
            timestamp: time(),
        );

        $context = new Context(
            messages: [
                new UserMessage([new TextContent('Look'), new ImageContent('aW1hZ2U=', 'image/png')], time()),
                $assistant,
                new ToolResultMessage('bad_id_1', 'write_file', [new TextContent('ok')], false, time()),
                new ToolResultMessage('next', 'write_file', [new TextContent('fail')], true, time()),
            ],
            systemPrompt: 'system prompt',
            tools: [
                new Tool('write_file', 'Write a file', Type::object([
                    'path' => Type::string(),
                ])),
            ],
        );

        $message = block($provider->stream(createBedrockModel(), $context, new BedrockOptions(
            reasoning: ThinkingLevel::Low,
            toolChoice: ['type' => 'tool', 'name' => 'write_file'],
            thinkingBudgets: [ThinkingLevel::Low->value => 2048],
            requestMetadata: ['trace' => 'abc'],
        ))->result());

        expect($message->stopReason)->toBe(StopReason::Stop);
    });

    it('streams text thinking and tool call events', function () {
        $provider = new BedrockProvider(
            transport: static fn (...$_args) => [
                ['messageStart' => ['role' => 'assistant']],
                ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hello ']]],
                ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'world']]],
                ['contentBlockStop' => ['contentBlockIndex' => 0]],
                ['contentBlockDelta' => ['contentBlockIndex' => 1, 'delta' => ['reasoningContent' => ['text' => 'Think', 'signature' => 'sig']]]],
                ['contentBlockStop' => ['contentBlockIndex' => 1]],
                ['contentBlockStart' => ['contentBlockIndex' => 2, 'start' => ['toolUse' => ['toolUseId' => 'tool_1', 'name' => 'edit']]]],
                ['contentBlockDelta' => ['contentBlockIndex' => 2, 'delta' => ['toolUse' => ['input' => '{"path":"README.md"}']]]],
                ['contentBlockStop' => ['contentBlockIndex' => 2]],
                ['messageStop' => ['stopReason' => 'tool_use']],
                ['metadata' => ['usage' => ['inputTokens' => 10, 'outputTokens' => 4, 'totalTokens' => 14]]],
            ],
        );

        $stream = $provider->stream(createBedrockModel(), new Context(messages: [new UserMessage('hi', time())]));
        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        $terminal = $events[array_key_last($events)];
        expect($terminal)->toBeInstanceOf(DoneEvent::class);
        expect($terminal->message->stopReason)->toBe(StopReason::ToolUse);
        expect($terminal->message->content[0]->text)->toBe('Hello world');
        expect($terminal->message->content[1])->toBeInstanceOf(ThinkingContent::class);
        expect($terminal->message->content[1]->thinking)->toBe('Think');
        expect($terminal->message->content[1]->thinkingSignature)->toBe('sig');
        expect($terminal->message->content[2])->toBeInstanceOf(ToolCall::class);
        expect($terminal->message->content[2]->arguments)->toBe(['path' => 'README.md']);
        expect($terminal->message->usage->input)->toBe(10);
        expect($terminal->message->usage->output)->toBe(4);
        expect($terminal->message->usage->totalTokens)->toBe(14);
    });
});
