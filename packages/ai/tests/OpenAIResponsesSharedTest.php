<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Api;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Event\ToolCallEndEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\OpenAI\OpenAIResponsesShared;
use Pi\AI\Provider;
use Pi\AI\StopReason;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

function createOpenAIResponsesModel(): Model
{
    return new Model(
        id: 'gpt-5-mini',
        name: 'GPT-5 Mini',
        api: new Api(Api::OPENAI_RESPONSES),
        provider: new Provider(Provider::OPENAI),
        baseUrl: 'https://api.openai.com/v1',
        reasoning: true,
        input: ['text'],
        cost: new UsageCost,
        contextWindow: 400000,
        maxTokens: 128000,
    );
}

describe('OpenAI responses shared helpers', function () {
    it('hashes foreign tool call item ids into fc_<hash> form', function () {
        $rawId = 'call_123|I9b95oN1wD/cHXKTw3PpRkL6KkCtzTJhUxMouMWYwHeTo2j3htzfSk7YPx2vifiIM4g3A8XXyOj8q4Bt6SLUG7gqY1E3ELkrkVQNHglRfUmWj84lqxJY+Puieb3VKyX0FB+83TUzn91cDMF/4gzt990IzqVrc+nIb9RRscRD070Du16q1glydVjWR0SBJsE6TbY/esOjFpqplogQqrajm1eI++f3eLi73R6q7hVusY0QbeFySVxABCjhN0lXB04caBe1rzHjYzul6MAXj7uq+0r17VLq+yrtyYhN12wkmFqHeqTyEei6EFPbMy24Nc+IbJlkP0OCg02W+gOnyBFcbi2ctvJFSOhSjt1CqBdqCnnhwUqXjbWiT0wh3DmLScRgTHmGkaI+oAcQQjfic65nxj+TnEkReA==';
        $assistant = new AssistantMessage(
            content: [new ToolCall($rawId, 'edit', ['path' => 'src/styles/app.css'])],
            api: new Api(Api::OPENAI_RESPONSES),
            provider: new Provider('github-copilot'),
            model: 'gpt-5.3-codex',
            usage: Usage::zero(),
            stopReason: StopReason::ToolUse,
            timestamp: time(),
        );
        $toolResult = new ToolResultMessage($rawId, 'edit', [new TextContent('ok')], false, time());
        $context = new Context(
            messages: [new UserMessage('Use the tool.', time()), $assistant, $toolResult],
            systemPrompt: 'You are concise.',
        );

        $input = OpenAIResponsesShared::convertMessages(createOpenAIResponsesModel(), $context, ['openai', 'openai-codex', 'opencode']);
        $functionCall = current(array_filter($input, static fn (array $item): bool => ($item['type'] ?? null) === 'function_call'));

        expect($functionCall)->not->toBeFalse();
        expect($functionCall['id'])->toStartWith('fc_');
        expect(strlen($functionCall['id']))->toBeLessThanOrEqual(64);
    });

    it('removes partial json from persisted tool call blocks at output item done', function () {
        $events = [
            ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_test', 'call_id' => 'call_test', 'name' => 'edit', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'delta' => '{"path":"README.md"'],
            ['type' => 'response.function_call_arguments.delta', 'delta' => ',"content":"updated"}'],
            ['type' => 'response.function_call_arguments.done', 'arguments' => '{"path":"README.md","content":"updated"}'],
            ['type' => 'response.output_item.done', 'item' => ['type' => 'function_call', 'id' => 'fc_test', 'call_id' => 'call_test', 'name' => 'edit', 'arguments' => '{"path":"README.md","content":"updated"}']],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12, 'input_tokens_details' => ['cached_tokens' => 0]]]],
        ];

        $stream = new AssistantMessageEventStream;
        $output = OpenAIResponsesShared::processStream($events, $stream, createOpenAIResponsesModel());
        $emitted = [];
        while (($event = block($stream->next())) !== null) {
            $emitted[] = $event;
        }

        expect($output->content)->toHaveCount(1);
        expect($output->content[0])->toBeInstanceOf(ToolCall::class);
        expect($output->content[0]->arguments)->toBe(['path' => 'README.md', 'content' => 'updated']);

        $toolCallEnd = current(array_filter($emitted, static fn ($event): bool => $event instanceof ToolCallEndEvent));
        expect($toolCallEnd)->toBeInstanceOf(ToolCallEndEvent::class);
        expect($toolCallEnd->toolCall->arguments)->toBe(['path' => 'README.md', 'content' => 'updated']);
    });
});
