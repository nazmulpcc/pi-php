<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\Models;
use Pi\AI\Provider;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

use function Pi\AI\calculateCost;
use function Pi\AI\getModel;
use function Pi\AI\getModels;
use function Pi\AI\getProviders;
use function Pi\AI\modelsAreEqual;
use function Pi\AI\supportsXhigh;

beforeEach(function () {
    Models::reload();
});

describe('Models', function () {
    it('loads models by provider and id', function () {
        $model = getModel(new Provider(Provider::OPENAI), 'gpt-5-mini');

        expect($model)->not->toBeNull();
        expect($model?->api->value)->toBe(Api::OPENAI_RESPONSES);
        expect($model?->provider->value)->toBe(Provider::OPENAI);
        expect($model?->input)->toBe(['text', 'image']);
    });

    it('returns provider lists and model lists', function () {
        $providers = array_map(static fn (Provider $provider): string => $provider->value, getProviders());
        $models = getModels(Provider::ANTHROPIC);

        expect($providers)->toBe([Provider::OPENAI, Provider::ANTHROPIC, Provider::OPENROUTER, Provider::OPENAI_CODEX]);
        expect(array_map(static fn ($model) => $model->id, $models))->toBe([
            'claude-opus-4-6',
            'claude-opus-4-7',
            'claude-sonnet-4-5',
        ]);
    });

    it('calculates usage cost totals from model pricing', function () {
        $model = getModel(Provider::OPENAI, 'gpt-5-mini');
        $usage = new Usage(
            input: 2000,
            output: 500,
            cacheRead: 1000,
            cacheWrite: 250,
            totalTokens: 3750,
            cost: new UsageCost,
        );

        expect($model)->not->toBeNull();

        $cost = calculateCost($model, $usage);

        expect($cost->input)->toBe(0.0005);
        expect($cost->output)->toBe(0.001);
        expect($cost->cacheRead)->toBe(0.000025);
        expect($cost->cacheWrite)->toBe(0.0000625);
        expect($cost->total)->toBeGreaterThan(0.0015874);
        expect($cost->total)->toBeLessThan(0.0015876);
    });

    it('detects xhigh support for supported model families', function () {
        expect(supportsXhigh(getModel(Provider::ANTHROPIC, 'claude-opus-4-6')))->toBeTrue();
        expect(supportsXhigh(getModel(Provider::ANTHROPIC, 'claude-opus-4-7')))->toBeTrue();
        expect(supportsXhigh(getModel(Provider::OPENROUTER, 'anthropic/claude-opus-4.6')))->toBeTrue();
        expect(supportsXhigh(getModel(Provider::OPENAI_CODEX, 'gpt-5.4')))->toBeTrue();
        expect(supportsXhigh(getModel(Provider::OPENAI_CODEX, 'gpt-5.5')))->toBeTrue();
        expect(supportsXhigh(getModel(Provider::ANTHROPIC, 'claude-sonnet-4-5')))->toBeFalse();
    });

    it('compares models by id and provider', function () {
        $a = getModel(Provider::OPENAI, 'gpt-5-mini');
        $b = getModel(Provider::OPENAI, 'gpt-5-mini');
        $c = getModel(Provider::OPENAI, 'gpt-4o-mini');

        expect(modelsAreEqual($a, $b))->toBeTrue();
        expect(modelsAreEqual($a, $c))->toBeFalse();
        expect(modelsAreEqual($a, null))->toBeFalse();
    });
});
