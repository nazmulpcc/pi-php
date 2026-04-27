<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\ApiRegistry;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\Content\TextContent;
use Pi\AI\Context;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\RegisterBuiltins;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

use function Pi\AI\complete;
use function Pi\AI\completeSimple;
use function Pi\AI\stream;
use function Pi\AI\streamSimple;

beforeEach(function () {
    ApiRegistry::clear();
    RegisterBuiltins::reset();
});

function createFacadeModel(): Model
{
    return new Model(
        id: 'test-model',
        name: 'Test Model',
        api: new Api(Api::OPENAI_RESPONSES),
        provider: new Provider(Provider::OPENAI),
        baseUrl: 'https://example.test',
        reasoning: false,
        input: ['text'],
        cost: new UsageCost,
        contextWindow: 8192,
        maxTokens: 2048,
    );
}

function createMissingFacadeModel(): Model
{
    return new Model(
        id: 'missing-model',
        name: 'Missing Model',
        api: new Api('missing-api'),
        provider: new Provider('missing-provider'),
        baseUrl: 'https://example.test',
        reasoning: false,
        input: ['text'],
        cost: new UsageCost,
        contextWindow: 8192,
        maxTokens: 2048,
    );
}

function createFacadeMessage(string $text): AssistantMessage
{
    return new AssistantMessage(
        content: [new TextContent($text)],
        api: new Api(Api::OPENAI_RESPONSES),
        provider: new Provider(Provider::OPENAI),
        model: 'test-model',
        usage: Usage::zero(),
        stopReason: StopReason::Stop,
        timestamp: 123456789,
    );
}

describe('stream facade', function () {
    it('delegates stream and complete calls to the registered provider', function () {
        $calls = ['stream' => 0, 'streamSimple' => 0];

        $provider = new class($calls) implements ApiProviderInterface
        {
            public function __construct(
                private array &$calls,
            ) {}

            public function getApi(): Api
            {
                return new Api(Api::OPENAI_RESPONSES);
            }

            public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
            {
                $this->calls['stream']++;

                return createDoneStream('normal');
            }

            public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
            {
                $this->calls['streamSimple']++;

                return createDoneStream('simple');
            }
        };

        ApiRegistry::registerProvider($provider, 'tests');

        $model = createFacadeModel();
        $context = new Context(messages: []);

        $streamResult = stream($model, $context);
        $streamEvent = block($streamResult->next());
        $completeResult = block(complete($model, $context));
        $simpleStreamResult = streamSimple($model, $context);
        $simpleStreamEvent = block($simpleStreamResult->next());
        $completeSimpleResult = block(completeSimple($model, $context));

        expect($streamEvent)->toBeInstanceOf(DoneEvent::class);
        expect($simpleStreamEvent)->toBeInstanceOf(DoneEvent::class);
        expect($completeResult->content[0]->text)->toBe('normal');
        expect($completeSimpleResult->content[0]->text)->toBe('simple');
        expect($calls)->toBe(['stream' => 2, 'streamSimple' => 2]);
    });

    it('throws when no provider is registered for the model api', function () {
        expect(fn () => stream(createMissingFacadeModel(), new Context(messages: [])))
            ->toThrow(RuntimeException::class, 'No API provider registered for api: missing-api');
    });
});

function createDoneStream(string $text): AssistantMessageEventStream
{
    $stream = new AssistantMessageEventStream;
    $stream->push(new DoneEvent(StopReason::Stop, createFacadeMessage($text)));

    return $stream;
}
