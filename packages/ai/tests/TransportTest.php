<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\CacheRetention;
use Pi\AI\EnvApiKeys;
use Pi\AI\SimpleCancellationToken;
use Pi\AI\StreamOptions;
use Pi\AI\Support\SimpleOptions;
use Pi\AI\ThinkingLevel;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Transport\ProviderError;
use Pi\AI\Transport\SseParser;

describe('SseParser', function () {
    it('parses single-line data frames', function () {
        $frame = 'data: {"type":"text"}';
        $result = SseParser::parseFrame($frame);
        expect($result)->toBe(['type' => 'text']);
    });

    it('parses multi-line data frames', function () {
        $frame = "data: {\"a\":\ndata: \"b\"}";
        $result = SseParser::parseFrame($frame);
        expect($result)->toBe(['a' => 'b']);
    });

    it('returns null for empty frames', function () {
        expect(SseParser::parseFrame(''))->toBeNull();
    });

    it('returns null for comment-only frames', function () {
        expect(SseParser::parseFrame(':comment'))->toBeNull();
    });

    it('returns null for [DONE] payloads', function () {
        expect(SseParser::parseFrame('data: [DONE]'))->toBeNull();
    });

    it('returns null for invalid json', function () {
        expect(SseParser::parseFrame('data: not-json'))->toBeNull();
    });
});

describe('EnvApiKeys', function () {
    it('resolves OPENAI_API_KEY for openai provider', function () {
        putenv('OPENAI_API_KEY=test-openai-key');
        expect(EnvApiKeys::getEnvApiKey('openai'))->toBe('test-openai-key');
        putenv('OPENAI_API_KEY');
    });

    it('resolves ANTHROPIC_API_KEY as fallback for anthropic', function () {
        putenv('ANTHROPIC_OAUTH_TOKEN');
        putenv('ANTHROPIC_API_KEY=test-anthropic-key');
        expect(EnvApiKeys::getEnvApiKey('anthropic'))->toBe('test-anthropic-key');
        putenv('ANTHROPIC_API_KEY');
    });

    it('returns null when no env key is set', function () {
        putenv('OPENAI_API_KEY');
        expect(EnvApiKeys::getEnvApiKey('openai'))->toBeNull();
    });

    it('finds all set env keys for a provider', function () {
        putenv('COPILOT_GITHUB_TOKEN=test1');
        putenv('GH_TOKEN=test2');
        $found = EnvApiKeys::findEnvKeys('github-copilot');
        expect($found)->toContain('COPILOT_GITHUB_TOKEN');
        expect($found)->toContain('GH_TOKEN');
        putenv('COPILOT_GITHUB_TOKEN');
        putenv('GH_TOKEN');
    });
});

describe('SimpleOptions', function () {
    it('clamps xhigh to high', function () {
        expect(SimpleOptions::clampReasoning(ThinkingLevel::Xhigh))->toBe(ThinkingLevel::High);
    });

    it('passes through non-xhigh reasoning levels', function () {
        expect(SimpleOptions::clampReasoning(ThinkingLevel::Medium))->toBe(ThinkingLevel::Medium);
        expect(SimpleOptions::clampReasoning(null))->toBeNull();
    });

    it('adjusts max tokens for thinking with default budgets', function () {
        $result = SimpleOptions::adjustMaxTokensForThinking(
            baseMaxTokens: 1000,
            modelMaxTokens: 200000,
            reasoningLevel: ThinkingLevel::High,
        );
        expect($result['maxTokens'])->toBe(1000 + 16384);
        expect($result['thinkingBudget'])->toBe(16384);
    });

    it('caps max tokens at model limit', function () {
        $result = SimpleOptions::adjustMaxTokensForThinking(
            baseMaxTokens: 1000,
            modelMaxTokens: 10000,
            reasoningLevel: ThinkingLevel::High,
        );
        expect($result['maxTokens'])->toBe(10000);
    });

    it('reduces thinking budget when max tokens would be exhausted', function () {
        $result = SimpleOptions::adjustMaxTokensForThinking(
            baseMaxTokens: 500,
            modelMaxTokens: 1000,
            reasoningLevel: ThinkingLevel::High,
        );
        expect($result['maxTokens'])->toBe(1000);
        expect($result['thinkingBudget'])->toBe(0);
    });

    it('uses custom budgets when provided', function () {
        $result = SimpleOptions::adjustMaxTokensForThinking(
            baseMaxTokens: 1000,
            modelMaxTokens: 200000,
            reasoningLevel: ThinkingLevel::Medium,
            customBudgets: ['medium' => 4096],
        );
        expect($result['thinkingBudget'])->toBe(4096);
    });
});

describe('HttpTransport', function () {
    it('throws when cancelled before request', function () {
        $token = new SimpleCancellationToken;
        $token->cancel();

        $transport = new HttpTransport(signal: $token);

        expect(fn () => block($transport->request('GET', 'http://localhost:1/test')))
            ->toThrow(ProviderError::class, 'cancelled');
    });

    it('throws when header contains newline injection', function () {
        $transport = new HttpTransport;

        expect(fn () => block($transport->request('GET', 'http://localhost:1/test', [
            'headers' => ["X-Evil: \r\n\r\nHTTP/1.1 200 OK" => 'value'],
        ])))->toThrow(ProviderError::class, 'Invalid header');
    });

    it('detects transient transport errors', function () {
        $transport = new HttpTransport(maxRetries: 2);
        $reflection = new ReflectionClass($transport);
        $method = $reflection->getMethod('isTransientTransportError');
        expect($method->invoke($transport, 'Connection timed out'))->toBeTrue();
        expect($method->invoke($transport, 'Could not resolve host'))->toBeTrue();
        expect($method->invoke($transport, 'Connection reset by peer'))->toBeTrue();
        expect($method->invoke($transport, 'Some random error'))->toBeFalse();
    });

    it('detects transient http statuses', function () {
        $transport = new HttpTransport;
        $reflection = new ReflectionClass($transport);
        $method = $reflection->getMethod('isTransientHttpStatus');
        expect($method->invoke($transport, 500))->toBeTrue();
        expect($method->invoke($transport, 502))->toBeTrue();
        expect($method->invoke($transport, 429))->toBeTrue();
        expect($method->invoke($transport, 400))->toBeFalse();
        expect($method->invoke($transport, 404))->toBeFalse();
    });

    it('truncates error response bodies', function () {
        $transport = new HttpTransport;
        $reflection = new ReflectionClass($transport);
        $method = $reflection->getMethod('createProviderError');
        $longBody = str_repeat('a', 5000);
        $error = $method->invoke($transport, 500, $longBody);
        expect($error)->toBeInstanceOf(ProviderError::class);
        expect(strlen($error->rawBody ?? ''))->toBeLessThan(5000);
        expect(str_contains($error->rawBody ?? '', '[truncated]'))->toBeTrue();
    });

    it('preserves short error response bodies', function () {
        $transport = new HttpTransport;
        $reflection = new ReflectionClass($transport);
        $method = $reflection->getMethod('createProviderError');
        $shortBody = json_encode(['error' => ['message' => 'Something went wrong']]);
        $error = $method->invoke($transport, 500, $shortBody);
        expect($error)->toBeInstanceOf(ProviderError::class);
        expect($error->rawBody)->toBe($shortBody);
        expect($error->getMessage())->toBe('Something went wrong');
    });
});

describe('CacheRetention', function () {
    it('has the expected enum values', function () {
        expect(CacheRetention::None->value)->toBe('none');
        expect(CacheRetention::Short->value)->toBe('short');
        expect(CacheRetention::Long->value)->toBe('long');
    });

    it('defaults to short in stream options', function () {
        $options = new StreamOptions;
        expect($options->cacheRetention)->toBe(CacheRetention::Short);
    });

    it('can be set to long for extended retention', function () {
        $options = new StreamOptions(cacheRetention: CacheRetention::Long);
        expect($options->cacheRetention)->toBe(CacheRetention::Long);
    });
});
