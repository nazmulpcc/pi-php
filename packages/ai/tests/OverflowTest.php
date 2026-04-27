<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Provider;
use Pi\AI\StopReason;
use Pi\AI\Usage;

use function Pi\AI\isContextOverflow;

function createOverflowErrorMessage(string $errorMessage): AssistantMessage
{
    return new AssistantMessage(
        content: [],
        api: new Api(Api::OPENAI_COMPLETIONS),
        provider: new Provider('ollama'),
        model: 'qwen3.5:35b',
        usage: Usage::zero(),
        stopReason: StopReason::Error,
        timestamp: 1,
        errorMessage: $errorMessage,
    );
}

describe('isContextOverflow', function () {
    it('detects explicit ollama prompt-too-long errors', function () {
        $message = createOverflowErrorMessage('400 `prompt too long; exceeded max context length by 100918 tokens`');
        expect(isContextOverflow($message, 32768))->toBeTrue();
    });

    it('does not treat generic non-overflow errors as overflow', function () {
        expect(isContextOverflow(createOverflowErrorMessage('500 `model runner crashed unexpectedly`'), 32768))->toBeFalse();
        expect(isContextOverflow(createOverflowErrorMessage('Throttling error: Too many tokens, please wait before trying again.'), 200000))->toBeFalse();
        expect(isContextOverflow(createOverflowErrorMessage('Service unavailable: The service is temporarily unavailable.'), 200000))->toBeFalse();
        expect(isContextOverflow(createOverflowErrorMessage('Rate limit exceeded, please retry after 30 seconds.'), 200000))->toBeFalse();
        expect(isContextOverflow(createOverflowErrorMessage('Too many requests. Please slow down.'), 200000))->toBeFalse();
    });
});
