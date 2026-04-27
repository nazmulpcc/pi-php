<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';
require_once aiPackageRoot('src/Google/GoogleOptions.php');
require_once aiPackageRoot('src/Google/GoogleShared.php');
require_once aiPackageRoot('src/Google/GoogleProvider.php');

use Pi\AI\Api;
use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Event\TextDeltaEvent;
use Pi\AI\Event\TextStartEvent;
use Pi\AI\Event\ThinkingDeltaEvent;
use Pi\AI\Event\ThinkingStartEvent;
use Pi\AI\Event\ToolCallDeltaEvent;
use Pi\AI\Event\ToolCallEndEvent;
use Pi\AI\Event\ToolCallStartEvent;
use Pi\AI\Google\GoogleOptions;
use Pi\AI\Google\GoogleProvider;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\Schema\Type;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\ThinkingLevel;
use Pi\AI\Tool;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

function hasEvent(array $events, string $class): bool
{
    foreach ($events as $event) {
        if ($event instanceof $class) {
            return true;
        }
    }

    return false;
}

function createGoogleModel(string $id, bool $reasoning = true): Model
{
    return new Model(
        id: $id,
        name: $id,
        api: new Api(Api::GOOGLE_GENERATIVE_AI),
        provider: new Provider(Provider::GOOGLE),
        baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
        reasoning: $reasoning,
        input: ['text', 'image'],
        cost: new UsageCost,
        contextWindow: 100000,
        maxTokens: 8192,
    );
}

describe('Google provider', function () {
    it('converts messages and streams text, thinking, and tool call events', function () {
        $provider = new GoogleProvider(
            transport: static function (Model $model, Context $context, GoogleOptions $options, array $params): iterable {
                expect($model)->toBeInstanceOf(Model::class);
                expect($context)->toBeInstanceOf(Context::class);
                expect($options)->toBeInstanceOf(GoogleOptions::class);
                expect($params['model'])->toBe('gemini-3-pro');
                expect($params['contents'][0]['role'])->toBe('user');
                expect($params['contents'][0]['parts'][0]['text'])->toBe('Show me this');
                expect($params['contents'][0]['parts'][1]['inlineData']['mimeType'])->toBe('image/png');
                expect($params['contents'][1]['role'])->toBe('model');
                expect($params['contents'][1]['parts'][0]['text'])->toBe('Working');
                expect($params['contents'][1]['parts'][1]['functionCall']['name'])->toBe('analyze');
                expect($params['contents'][2]['parts'][0]['functionResponse']['parts'][0]['inlineData']['mimeType'])->toBe('image/png');
                expect($params['config']['toolConfig']['functionCallingConfig']['mode'])->toBe('ANY');
                expect($params['config']['thinkingConfig']['thinkingLevel'])->toBe('HIGH');
                expect($params['config']['tools'][0]['functionDeclarations'][0]['parametersJsonSchema']['type'])->toBe('object');

                return [
                    [
                        'responseId' => 'resp_1',
                        'usageMetadata' => [
                            'promptTokenCount' => 10,
                            'cachedContentTokenCount' => 2,
                            'candidatesTokenCount' => 3,
                            'thoughtsTokenCount' => 4,
                            'totalTokenCount' => 19,
                        ],
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'Hello ']]],
                            'finishReason' => null,
                        ]],
                    ],
                    [
                        'responseId' => 'resp_1',
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'ponder', 'thought' => true]]],
                            'finishReason' => null,
                        ]],
                    ],
                    [
                        'responseId' => 'resp_1',
                        'candidates' => [[
                            'content' => ['parts' => [[
                                'functionCall' => [
                                    'name' => 'analyze',
                                    'id' => 'call_1',
                                    'args' => ['topic' => 'sales'],
                                ],
                            ]]],
                            'finishReason' => 'STOP',
                        ]],
                    ],
                ];
            },
        );

        $stream = $provider->stream(createGoogleModel('gemini-3-pro'), new Context(
            messages: [
                new UserMessage([new TextContent('Show me this'), new ImageContent('aGVsbG8=', 'image/png')], time()),
                new AssistantMessage(
                    content: [
                        new TextContent('Working'),
                        new ToolCall('call_1', 'analyze', ['topic' => 'sales']),
                    ],
                    api: new Api(Api::GOOGLE_GENERATIVE_AI),
                    provider: new Provider(Provider::GOOGLE),
                    model: 'gemini-3-pro',
                    usage: Usage::zero(),
                    stopReason: StopReason::ToolUse,
                    timestamp: time(),
                ),
                new ToolResultMessage(
                    toolCallId: 'call_1',
                    toolName: 'analyze',
                    content: [new TextContent('Done'), new ImageContent('aGVsbG8=', 'image/png')],
                    isError: false,
                    timestamp: time(),
                ),
            ],
            systemPrompt: 'Be concise.',
            tools: [new Tool('analyze', 'Analyze the image', Type::object(['topic' => Type::string()]))],
        ), new GoogleOptions(
            thinkingEnabled: true,
            thinkingLevel: 'HIGH',
            toolChoice: 'any',
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        expect($events[0])->not()->toBeInstanceOf(ErrorEvent::class);
        expect(hasEvent($events, TextStartEvent::class))->toBeTrue();
        expect(hasEvent($events, TextDeltaEvent::class))->toBeTrue();
        expect(hasEvent($events, ThinkingStartEvent::class))->toBeTrue();
        expect(hasEvent($events, ThinkingDeltaEvent::class))->toBeTrue();
        expect(hasEvent($events, ToolCallStartEvent::class))->toBeTrue();
        expect(hasEvent($events, ToolCallDeltaEvent::class))->toBeTrue();
        expect(hasEvent($events, ToolCallEndEvent::class))->toBeTrue();
        expect($events[array_key_last($events)])->toBeInstanceOf(DoneEvent::class);

        $message = block($stream->result());
        expect($message->responseId)->toBe('resp_1');
        expect($message->stopReason)->toBe(StopReason::ToolUse);
        expect($message->usage->input)->toBe(8);
        expect($message->usage->output)->toBe(7);
        expect($message->content[0])->toBeInstanceOf(TextContent::class);
        expect($message->content[1])->toBeInstanceOf(ThinkingContent::class);
        expect($message->content[2])->toBeInstanceOf(ToolCall::class);
    });

    it('maps simple reasoning to a thinking level for Gemini 3 models', function () {
        $provider = new GoogleProvider(
            transport: static function (Model $model, Context $context, GoogleOptions $options, array $params): iterable {
                expect($model)->toBeInstanceOf(Model::class);
                expect($context)->toBeInstanceOf(Context::class);
                expect($options)->toBeInstanceOf(GoogleOptions::class);
                expect($params['config']['thinkingConfig']['includeThoughts'])->toBeTrue();
                expect($params['config']['thinkingConfig']['thinkingLevel'])->toBe('HIGH');
                expect(array_key_exists('thinkingBudget', $params['config']['thinkingConfig']))->toBeFalse();

                return [
                    [
                        'responseId' => 'resp_2',
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'ok']]],
                            'finishReason' => 'STOP',
                        ]],
                    ],
                ];
            },
        );

        $stream = $provider->streamSimple(createGoogleModel('gemini-3-flash'), new Context(
            messages: [new UserMessage('hi', time())],
        ), new SimpleStreamOptions(reasoning: ThinkingLevel::High));
        $message = block($stream->result());

        expect($message->stopReason)->toBe(StopReason::Stop);
        expect($message->content[0])->toBeInstanceOf(TextContent::class);
    });

    it('maps simple reasoning to a budget for non-Gemini 3 models and splits older image tool results', function () {
        $provider = new GoogleProvider(
            transport: static function (Model $model, Context $context, GoogleOptions $options, array $params): iterable {
                expect($model)->toBeInstanceOf(Model::class);
                expect($context)->toBeInstanceOf(Context::class);
                expect($options)->toBeInstanceOf(GoogleOptions::class);
                expect($params['config']['thinkingConfig']['includeThoughts'])->toBeTrue();
                expect($params['config']['thinkingConfig']['thinkingBudget'])->toBe(2048);
                expect($params['contents'][1]['parts'][0]['functionResponse'])->toBeTruthy();
                expect($params['contents'][2]['parts'][0]['text'])->toBe('Tool result image:');
                expect($params['contents'][2]['parts'][1]['inlineData']['mimeType'])->toBe('image/png');

                return [
                    [
                        'responseId' => 'resp_3',
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'ok']]],
                            'finishReason' => 'STOP',
                        ]],
                    ],
                ];
            },
        );

        $stream = $provider->streamSimple(createGoogleModel('gemini-2.5-flash'), new Context(
            messages: [
                new UserMessage('hi', time()),
                new ToolResultMessage(
                    toolCallId: 'call_2',
                    toolName: 'render_image',
                    content: [new TextContent('Rendered'), new ImageContent('aGVsbG8=', 'image/png')],
                    isError: false,
                    timestamp: time(),
                ),
            ],
        ), new SimpleStreamOptions(reasoning: ThinkingLevel::Low));
        $message = block($stream->result());

        expect($message->stopReason)->toBe(StopReason::Stop);
        expect($message->content[0])->toBeInstanceOf(TextContent::class);
    });
});
