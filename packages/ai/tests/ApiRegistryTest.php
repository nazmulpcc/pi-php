<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\ApiRegistry;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\Context;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\RegisterBuiltins;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StreamOptions;
use Pi\AI\UsageCost;

beforeEach(function () {
    ApiRegistry::clear();
    RegisterBuiltins::reset();
});

function createModelForApi(Api $api): Model
{
    return new Model(
        id: 'test-model',
        name: 'Test Model',
        api: $api,
        provider: new Provider(Provider::OPENAI),
        baseUrl: 'https://example.test',
        reasoning: false,
        input: ['text'],
        cost: new UsageCost,
        contextWindow: 8192,
        maxTokens: 2048,
    );
}

function createProvider(Api $api): ApiProviderInterface
{
    return new class($api) implements ApiProviderInterface
    {
        public function __construct(
            private readonly Api $api,
        ) {}

        public function getApi(): Api
        {
            return $this->api;
        }

        public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
        {
            return new AssistantMessageEventStream;
        }

        public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
        {
            return new AssistantMessageEventStream;
        }
    };
}

describe('ApiRegistry', function () {
    it('registers and retrieves providers by api', function () {
        $provider = createProvider(new Api(Api::OPENAI_RESPONSES));

        ApiRegistry::registerProvider($provider, 'tests');

        expect(ApiRegistry::getProvider(new Api(Api::OPENAI_RESPONSES)))->not->toBeNull();
        expect(ApiRegistry::getProviders())->toHaveCount(1);
        expect(ApiRegistry::getProviders()[0]->getApi()->value)->toBe(Api::OPENAI_RESPONSES);
    });

    it('unregisters providers by source id', function () {
        ApiRegistry::registerProvider(createProvider(new Api(Api::OPENAI_RESPONSES)), 'tests');
        ApiRegistry::registerProvider(createProvider(new Api(Api::OPENAI_COMPLETIONS)), 'other');

        ApiRegistry::unregisterProviders('tests');

        expect(ApiRegistry::getProvider(new Api(Api::OPENAI_RESPONSES)))->toBeNull();
        expect(ApiRegistry::getProvider(new Api(Api::OPENAI_COMPLETIONS)))->not->toBeNull();
    });

    it('clears all providers', function () {
        ApiRegistry::registerProvider(createProvider(new Api(Api::OPENAI_RESPONSES)), 'tests');
        ApiRegistry::clear();

        expect(ApiRegistry::getProviders())->toBe([]);
    });

    it('rejects mismatched model api when invoking a registered provider', function () {
        $provider = createProvider(new Api(Api::OPENAI_RESPONSES));
        $registered = null;

        ApiRegistry::registerProvider($provider, 'tests');
        $registered = ApiRegistry::getProvider(new Api(Api::OPENAI_RESPONSES));

        expect($registered)->not->toBeNull();

        expect(fn () => $registered?->stream(
            createModelForApi(new Api(Api::OPENAI_COMPLETIONS)),
            new Context(messages: []),
        ))->toThrow(InvalidArgumentException::class, 'Mismatched api: openai-completions expected openai-responses');
    });
});
