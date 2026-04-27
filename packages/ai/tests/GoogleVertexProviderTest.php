<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';
require_once aiPackageRoot('src/Google/Vertex/GoogleVertexProvider.php');
require_once aiPackageRoot('src/Google/Vertex/GoogleVertexOptions.php');
require_once aiPackageRoot('src/Google/GoogleShared.php');

use Pi\AI\Api;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Event\StartEvent;
use Pi\AI\Event\TextDeltaEvent;
use Pi\AI\Event\TextEndEvent;
use Pi\AI\Event\TextStartEvent;
use Pi\AI\Event\ThinkingDeltaEvent;
use Pi\AI\Event\ThinkingEndEvent;
use Pi\AI\Event\ThinkingStartEvent;
use Pi\AI\Event\ToolCallDeltaEvent;
use Pi\AI\Event\ToolCallEndEvent;
use Pi\AI\Event\ToolCallStartEvent;
use Pi\AI\Google\Vertex\GoogleVertexOptions;
use Pi\AI\Google\Vertex\GoogleVertexProvider;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\Schema\Type;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\ThinkingLevel;
use Pi\AI\Tool;
use Pi\AI\UsageCost;

function createVertexModel(string $id, bool $reasoning = true): Model
{
    return new Model(
        id: $id,
        name: $id,
        api: new Api(Api::GOOGLE_VERTEX),
        provider: new Provider(Provider::GOOGLE_VERTEX),
        baseUrl: '',
        reasoning: $reasoning,
        input: ['text', 'image'],
        cost: new UsageCost,
        contextWindow: 1000000,
        maxTokens: 8192,
    );
}

describe('Google Vertex provider', function () {
    it('builds a stream from a fake transport and emits text, thinking, and tool events', function () {
        $provider = new GoogleVertexProvider(
            transport: static function (Model $model, Context $context, GoogleVertexOptions $options, array $params): iterable {
                expect($params)->toHaveKey('model');
                expect($params)->toHaveKey('contents');
                expect($params)->toHaveKey('config');

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
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'ponder', 'thought' => true]]],
                            'finishReason' => null,
                        ]],
                    ],
                    [
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

        $stream = $provider->stream(createVertexModel('gemini-3-pro'), new Context(
            messages: [
                new UserMessage('Show me this', time()),
            ],
            systemPrompt: 'Be concise.',
            tools: [new Tool('analyze', 'Analyze the image', Type::object(['topic' => Type::string()]))],
        ), new GoogleVertexOptions(
            project: 'test-project',
            location: 'us-central1',
            thinking: ['enabled' => true, 'level' => 'HIGH'],
            toolChoice: 'any',
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        expect($events[0])->not()->toBeInstanceOf(ErrorEvent::class);
        expect($events[0])->toBeInstanceOf(StartEvent::class);
        expect($events[1])->toBeInstanceOf(TextStartEvent::class);
        expect($events[2])->toBeInstanceOf(TextDeltaEvent::class);
        expect($events[2]->delta)->toBe('Hello ');
        expect($events[3])->toBeInstanceOf(TextEndEvent::class);
        expect($events[4])->toBeInstanceOf(ThinkingStartEvent::class);
        expect($events[5])->toBeInstanceOf(ThinkingDeltaEvent::class);
        expect($events[6])->toBeInstanceOf(ThinkingEndEvent::class);
        expect($events[7])->toBeInstanceOf(ToolCallStartEvent::class);
        expect($events[8])->toBeInstanceOf(ToolCallDeltaEvent::class);
        expect($events[9])->toBeInstanceOf(ToolCallEndEvent::class);
        expect($events[array_key_last($events)])->toBeInstanceOf(DoneEvent::class);

        $message = block($stream->result());
        expect($message->responseId)->toBe('resp_1');
        expect($message->stopReason)->toBe(StopReason::ToolUse);
        expect($message->usage->input)->toBe(8);
        expect($message->usage->output)->toBe(7);
    });

    it('streams thinking content with thought signatures', function () {
        $provider = new GoogleVertexProvider(
            transport: static function (): iterable {
                return [
                    [
                        'candidates' => [[
                            'content' => ['parts' => [['thought' => true, 'text' => 'Let me think', 'thoughtSignature' => 'abc123==']]],
                            'finishReason' => null,
                        ]],
                    ],
                    [
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'Done']]],
                            'finishReason' => 'STOP',
                        ]],
                    ],
                ];
            },
        );

        $stream = $provider->stream(createVertexModel('gemini-2.5-flash'), new Context(
            messages: [new UserMessage('Think', time())],
        ), new GoogleVertexOptions(
            project: 'test-project',
            location: 'us-central1',
            thinking: ['enabled' => true],
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        expect($events[1])->toBeInstanceOf(ThinkingStartEvent::class);
        expect($events[2])->toBeInstanceOf(ThinkingDeltaEvent::class);
        expect($events[3])->toBeInstanceOf(ThinkingEndEvent::class);
        expect($events[4])->toBeInstanceOf(TextStartEvent::class);
        expect($events[5])->toBeInstanceOf(TextDeltaEvent::class);
    });

    it('maps simple reasoning to thinking level for Gemini 3 models', function () {
        $provider = new GoogleVertexProvider(
            transport: static function (Model $model, Context $context, GoogleVertexOptions $options, array $params): iterable {
                expect($params['config']['thinkingConfig']['thinkingLevel'] ?? null)->toBe('HIGH');

                return [
                    [
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'ok']]],
                            'finishReason' => 'STOP',
                        ]],
                    ],
                ];
            },
        );

        $stream = $provider->streamSimple(createVertexModel('gemini-3-pro'), new Context(
            messages: [new UserMessage('Hello', time())],
        ), new SimpleStreamOptions(
            reasoning: ThinkingLevel::High,
        ));

        block($stream->result());
    });

    it('maps simple reasoning to budget for non-Gemini 3 models', function () {
        $provider = new GoogleVertexProvider(
            transport: static function (Model $model, Context $context, GoogleVertexOptions $options, array $params): iterable {
                expect($params['config']['thinkingConfig']['thinkingBudget'] ?? null)->toBe(8192);

                return [
                    [
                        'candidates' => [[
                            'content' => ['parts' => [['text' => 'ok']]],
                            'finishReason' => 'STOP',
                        ]],
                    ],
                ];
            },
        );

        $stream = $provider->streamSimple(createVertexModel('gemini-2.5-flash'), new Context(
            messages: [new UserMessage('Hello', time())],
        ), new SimpleStreamOptions(
            reasoning: ThinkingLevel::Medium,
        ));

        block($stream->result());
    });

    it('requires project and location for default transport', function () {
        $provider = new GoogleVertexProvider;

        $stream = $provider->stream(createVertexModel('gemini-2.5-flash'), new Context(
            messages: [new UserMessage('Hello', time())],
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        expect($events[0])->toBeInstanceOf(ErrorEvent::class);
        expect($events[0]->error->errorMessage)->toContain('project ID');
    });

    it('streams tool call events', function () {
        $provider = new GoogleVertexProvider(
            transport: static function (): iterable {
                return [
                    [
                        'candidates' => [[
                            'content' => ['parts' => [[
                                'functionCall' => ['name' => 'get_time', 'args' => ['timezone' => 'UTC']],
                            ]]],
                            'finishReason' => 'STOP',
                        ]],
                    ],
                ];
            },
        );

        $stream = $provider->stream(createVertexModel('gemini-2.5-flash', reasoning: false), new Context(
            messages: [new UserMessage('What time is it?', time())],
            tools: [new Tool('get_time', 'Get current time', ['type' => 'object', 'properties' => []])],
        ), new GoogleVertexOptions(
            project: 'test-project',
            location: 'us-central1',
        ));

        $events = [];
        while (($event = block($stream->next())) !== null) {
            $events[] = $event;
        }

        expect($events[1])->toBeInstanceOf(ToolCallStartEvent::class);
        expect($events[2])->toBeInstanceOf(ToolCallDeltaEvent::class);
        expect($events[3])->toBeInstanceOf(ToolCallEndEvent::class);
        expect($events[3]->toolCall->name)->toBe('get_time');
        expect($events[4])->toBeInstanceOf(DoneEvent::class);
        expect($events[4]->message->stopReason)->toBe(StopReason::ToolUse);
    });
});
