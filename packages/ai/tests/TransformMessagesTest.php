<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\StopReason;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

use function Pi\AI\transformMessages;

function createTransformModel(string $provider, string $api, string $id, array $input = ['text']): Model
{
    return new Model(
        id: $id,
        name: $id,
        api: new Api($api),
        provider: new Provider($provider),
        baseUrl: 'https://example.test',
        reasoning: true,
        input: $input,
        cost: new UsageCost,
        contextWindow: 100000,
        maxTokens: 10000,
    );
}

function createTransformAssistant(array $content, string $provider = Provider::OPENAI, string $api = Api::OPENAI_RESPONSES, string $model = 'gpt-5-mini', StopReason $stopReason = StopReason::ToolUse): AssistantMessage
{
    return new AssistantMessage(
        content: $content,
        api: new Api($api),
        provider: new Provider($provider),
        model: $model,
        usage: Usage::zero(),
        stopReason: $stopReason,
        timestamp: 100,
    );
}

describe('transformMessages', function () {
    it('normalizes tool call ids and rewrites tool results for cross-provider replay', function () {
        $messages = [
            new UserMessage('use the tool', 1),
            createTransformAssistant([
                new ToolCall('call|very-long', 'echo', ['message' => 'hi'], 'opaque-thought'),
            ]),
            new ToolResultMessage('call|very-long', 'echo', [new TextContent('hi')], false, 2),
        ];

        $transformed = transformMessages(
            $messages,
            createTransformModel(Provider::OPENROUTER, Api::OPENAI_COMPLETIONS, 'openai/gpt-5.2-codex'),
            static fn (string $id): string => str_replace('call|very-long', 'normalized-id', $id),
        );

        expect($transformed[1])->toBeInstanceOf(AssistantMessage::class);
        expect($transformed[1]->content[0])->toBeInstanceOf(ToolCall::class);
        expect($transformed[1]->content[0]->id)->toBe('normalized-id');
        expect($transformed[1]->content[0]->thoughtSignature)->toBeNull();
        expect($transformed[2])->toBeInstanceOf(ToolResultMessage::class);
        expect($transformed[2]->toolCallId)->toBe('normalized-id');
    });

    it('inserts synthetic tool results for orphaned tool calls and skips errored assistants', function () {
        $messages = [
            new UserMessage('calculate', 1),
            createTransformAssistant([
                new ToolCall('tool-1', 'echo', ['text' => 'hi']),
            ]),
            createTransformAssistant([
                new TextContent('partial'),
            ], stopReason: StopReason::Error),
            new UserMessage('never mind', 2),
        ];

        $transformed = transformMessages($messages, createTransformModel(Provider::OPENROUTER, Api::OPENAI_COMPLETIONS, 'openai/gpt-5.2-codex'));

        expect($transformed)->toHaveCount(4);
        expect($transformed[1])->toBeInstanceOf(AssistantMessage::class);
        expect($transformed[2])->toBeInstanceOf(ToolResultMessage::class);
        expect($transformed[2]->toolCallId)->toBe('tool-1');
        expect($transformed[2]->isError)->toBeTrue();
        expect($transformed[3])->toBeInstanceOf(UserMessage::class);
    });

    it('downgrades unsupported images and converts cross-model thinking to text', function () {
        $messages = [
            new UserMessage([
                new TextContent('describe this'),
                new ImageContent('base64', 'image/png'),
            ], 1),
            createTransformAssistant([
                new ThinkingContent('chain of thought'),
                new TextContent('answer'),
            ], provider: Provider::ANTHROPIC, api: Api::ANTHROPIC_MESSAGES, model: 'claude-sonnet-4-5', stopReason: StopReason::Stop),
            new ToolResultMessage('tool-1', 'render', [
                new ImageContent('chart', 'image/png'),
                new TextContent('rendered'),
            ], false, 2),
        ];

        $transformed = transformMessages($messages, createTransformModel(Provider::OPENAI, Api::OPENAI_RESPONSES, 'gpt-5-mini', ['text']));

        expect($transformed[0])->toBeInstanceOf(UserMessage::class);
        expect($transformed[0]->content)->toHaveCount(2);
        expect($transformed[0]->content[1]->text)->toBe('(image omitted: model does not support images)');
        expect($transformed[1])->toBeInstanceOf(AssistantMessage::class);
        expect($transformed[1]->content[0])->toBeInstanceOf(TextContent::class);
        expect($transformed[1]->content[0]->text)->toBe('chain of thought');
        expect($transformed[2])->toBeInstanceOf(ToolResultMessage::class);
        expect($transformed[2]->content[0]->text)->toBe('(tool image omitted: model does not support images)');
    });
});
