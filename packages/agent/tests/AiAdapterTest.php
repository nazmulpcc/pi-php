<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\AgentContext;
use Pi\Agent\AiAdapter;
use Pi\Agent\Content\ThinkingContent;
use Pi\AI\Api;
use Pi\AI\Content\ThinkingContent as AiThinkingContent;
use Pi\AI\Message\AssistantMessage as AiAssistantMessage;
use Pi\AI\Provider;
use Pi\AI\Usage;

describe('AiAdapter', function () {
    it('preserves thinking signature and redacted flag when round-tripping assistant messages', function () {
        $aiMessage = new AiAssistantMessage(
            content: [
                new AiThinkingContent('step one', '{"id":"sig-1"}', false),
                new AiThinkingContent('[redacted]', null, true),
            ],
            api: new Api('openai-responses'),
            provider: new Provider('openai'),
            model: 'o3-mini',
            usage: Usage::zero(),
            stopReason: Pi\AI\StopReason::Stop,
            timestamp: time(),
        );

        $agentMessage = AiAdapter::toAgentAssistantMessage($aiMessage);
        expect($agentMessage->content[0])->toBeInstanceOf(ThinkingContent::class);
        expect($agentMessage->content[0]->thinking)->toBe('step one');
        expect($agentMessage->content[0]->thinkingSignature)->toBe('{"id":"sig-1"}');
        expect($agentMessage->content[0]->redacted)->toBeFalse();

        expect($agentMessage->content[1])->toBeInstanceOf(ThinkingContent::class);
        expect($agentMessage->content[1]->thinking)->toBe('[redacted]');
        expect($agentMessage->content[1]->thinkingSignature)->toBeNull();
        expect($agentMessage->content[1]->redacted)->toBeTrue();

        $context = new AgentContext('', [$agentMessage], []);
        $aiContext = AiAdapter::toAiContext($context);
        $roundTripped = $aiContext->messages[0];
        expect($roundTripped->content[0])->toBeInstanceOf(AiThinkingContent::class);
        expect($roundTripped->content[0]->thinking)->toBe('step one');
        expect($roundTripped->content[0]->thinkingSignature)->toBe('{"id":"sig-1"}');
        expect($roundTripped->content[0]->redacted)->toBeFalse();

        expect($roundTripped->content[1])->toBeInstanceOf(AiThinkingContent::class);
        expect($roundTripped->content[1]->thinking)->toBe('[redacted]');
        expect($roundTripped->content[1]->thinkingSignature)->toBeNull();
        expect($roundTripped->content[1]->redacted)->toBeTrue();
    });
});
