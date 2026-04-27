<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Tool\AgentToolResult;
use Pi\AI\Schema\Type;
use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Event\CodingAgentEvent;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\CodingAgent\Session\InMemorySessionStore;
use Pi\CodingAgent\Tool\AbstractTool;

use function Pi\AI\fauxAssistantMessage;
use function Pi\AI\registerFauxProvider;

describe('Coding agent runtime', function () {
    it('creates an in-memory runtime and prompts through pi ai', function () {
        $registration = registerFauxProvider();
        $registration->setResponses([
            fauxAssistantMessage('hello from runtime'),
        ]);

        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            model: $registration->getModel(),
            sessionStore: new InMemorySessionStore,
        ));

        $events = [];
        $runtime->subscribe(function (CodingAgentEvent $event) use (&$events): void {
            $events[] = $event->type;
        });

        codingAgentBlock($runtime->prompt('hi'));

        $state = $runtime->getState();
        expect($state->messages)->toHaveCount(2);
        expect($state->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($state->messages[1]->content[0]->text)->toBe('hello from runtime');
        expect($events)->toContain('agent_start', 'message_end', 'agent_end');

        $registration->unregister();
    });

    it('persists and resumes file-backed sessions', function () {
        $dir = codingAgentTempDir();
        $store = new FilesystemSessionStore($dir.'/sessions');
        $registration = registerFauxProvider();
        $registration->setResponses([
            fauxAssistantMessage('first answer'),
        ]);

        $factory = new CodingAgentRuntimeFactory;
        $runtime = $factory->create(new CodingAgentConfig(
            model: $registration->getModel(),
            sessionStore: $store,
            cwd: $dir,
        ));

        codingAgentBlock($runtime->prompt('first'));
        $sessionId = $runtime->getState()->sessionId;

        $resumed = $factory->resume(new CodingAgentConfig(
            sessionStore: $store,
            cwd: $dir,
        ), $sessionId);

        expect($resumed->getState()->messages)->toHaveCount(2);
        expect($resumed->getState()->messages[1])->toBeInstanceOf(AssistantMessage::class);
        expect($resumed->getState()->messages[1]->content[0]->text)->toBe('first answer');

        $registration->unregister();
        codingAgentDeleteDir($dir);
    });

    it('compacts older messages into a summary user message', function () {
        $registration = registerFauxProvider();
        $registration->setResponses([
            fauxAssistantMessage('answer one'),
            fauxAssistantMessage('answer two'),
        ]);

        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            model: $registration->getModel(),
            sessionStore: new InMemorySessionStore,
        ));

        codingAgentBlock($runtime->prompt('one'));
        codingAgentBlock($runtime->prompt('two'));
        $summary = $runtime->compact(2);

        $messages = $runtime->getState()->messages;
        expect($summary['summary'])->toContain('user: one');
        expect($summary['changed'])->toBeTrue();
        expect($messages[0]->content[0]->text)->toContain('Compacted conversation summary');
        expect($messages)->toHaveCount(3);

        $registration->unregister();
    });

    it('supports custom host-provided tools and allowlists', function () {
        $tool = new class extends AbstractTool
        {
            public function __construct()
            {
                parent::__construct('custom', 'custom', Type::object([]));
            }

            protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
            {
                return new AgentToolResult([new TextContent('ok')]);
            }
        };

        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            tools: [$tool],
            allowedToolNames: ['custom'],
            sessionStore: new InMemorySessionStore,
        ));

        expect($runtime->getState()->toolNames)->toBe(['custom']);
    });
});
