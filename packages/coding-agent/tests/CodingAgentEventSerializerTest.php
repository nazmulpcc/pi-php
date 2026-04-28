<?php

declare(strict_types=1);

use Pi\Agent\Content\TextContent;
use Pi\Agent\Event\MessageUpdateEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\StopReason;
use Pi\AI\Api;
use Pi\AI\Content\TextContent as AiTextContent;
use Pi\AI\Event\TextDeltaEvent;
use Pi\AI\Message\AssistantMessage as AiAssistantMessage;
use Pi\AI\Provider;
use Pi\AI\StopReason as AiStopReason;
use Pi\AI\Usage;
use Pi\CodingAgent\Event\CodingAgentEventSerializer;

describe('Coding agent event serializer', function () {
    it('serializes assistant text delta events into structured payloads', function () {
        $agentMessage = new AssistantMessage(
            content: [new TextContent('Hello')],
            api: 'openai-responses',
            provider: 'openai-codex',
            model: 'gpt-5.4-mini',
            stopReason: StopReason::Done,
            timestamp: time(),
        );

        $partial = new AiAssistantMessage(
            content: [new AiTextContent('Hel')],
            api: new Api(Api::OPENAI_RESPONSES),
            provider: new Provider(Provider::OPENAI_CODEX),
            model: 'gpt-5.4-mini',
            usage: Usage::zero(),
            stopReason: AiStopReason::Stop,
            timestamp: time(),
        );

        $event = CodingAgentEventSerializer::fromAgentEvent(
            new MessageUpdateEvent($agentMessage, new TextDeltaEvent(0, 'Hel', $partial)),
        );

        expect($event->payload['assistantMessageEvent'] ?? null)->toMatchArray([
            'type' => 'text_delta',
            'contentIndex' => 0,
            'delta' => 'Hel',
        ]);
        expect($event->payload['assistantMessageEvent']['partial']['content'][0]['text'] ?? null)->toBe('Hel');
    });
});
