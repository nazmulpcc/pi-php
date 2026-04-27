<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\CacheRetention;
use Pi\AI\CancellationToken;
use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\MessageRole;
use Pi\AI\Provider;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

describe('AI core types', function () {
    it('preserves assistant metadata and content signatures', function () {
        $message = new AssistantMessage(
            content: [
                new TextContent('Hello', 'text-sig'),
                new ThinkingContent('Need to reason', 'thinking-sig', true),
                new ToolCall('call_1', 'echo', ['text' => 'Hello'], 'thought-sig'),
            ],
            api: new Api(Api::OPENAI_RESPONSES),
            provider: new Provider(Provider::OPENAI),
            model: 'gpt-5-mini',
            usage: new Usage(
                input: 10,
                output: 4,
                cacheRead: 1,
                cacheWrite: 2,
                totalTokens: 17,
                cost: new UsageCost(0.1, 0.2, 0.01, 0.02, 0.33),
            ),
            stopReason: StopReason::ToolUse,
            timestamp: 123456789,
            responseId: 'resp_123',
        );

        expect($message->getRole())->toBe(MessageRole::Assistant);
        expect($message->api->value)->toBe(Api::OPENAI_RESPONSES);
        expect($message->provider->value)->toBe(Provider::OPENAI);
        expect($message->responseId)->toBe('resp_123');
        expect($message->usage->totalTokens)->toBe(17);
        expect($message->content[0]->textSignature)->toBe('text-sig');
        expect($message->content[1]->thinkingSignature)->toBe('thinking-sig');
        expect($message->content[1]->redacted)->toBeTrue();
        expect($message->content[2]->thoughtSignature)->toBe('thought-sig');
    });

    it('supports image tool results and string user messages', function () {
        $user = new UserMessage('Hello', 1000);
        $toolResult = new ToolResultMessage(
            toolCallId: 'call_1',
            toolName: 'render_chart',
            content: [
                new TextContent('Rendered chart'),
                new ImageContent('base64-data', 'image/png'),
            ],
            isError: false,
            timestamp: 2000,
        );

        expect($user->getRole())->toBe(MessageRole::User);
        expect($toolResult->getRole())->toBe(MessageRole::ToolResult);
        expect($toolResult->content[1])->toBeInstanceOf(ImageContent::class);
        expect($toolResult->content[1]->mimeType)->toBe('image/png');
    });

    it('uses typed cancellation tokens and callbacks in stream options', function () {
        $token = new class implements CancellationToken
        {
            public function isCancelled(): bool
            {
                return false;
            }
        };

        $payloadHook = static fn (mixed $payload): mixed => $payload;
        $responseHook = static fn (): null => null;

        $options = new StreamOptions(
            signal: $token,
            cacheRetention: CacheRetention::Long,
            onPayload: $payloadHook,
            onResponse: $responseHook,
        );

        $simpleOptions = new SimpleStreamOptions(
            signal: $token,
            cacheRetention: CacheRetention::None,
            onPayload: $payloadHook,
            onResponse: $responseHook,
        );

        expect($options->signal)->toBe($token);
        expect($options->onPayload)->toBe($payloadHook);
        expect($options->onResponse)->toBe($responseHook);
        expect($simpleOptions->signal)->toBe($token);
        expect($simpleOptions->cacheRetention)->toBe(CacheRetention::None);
    });
});
