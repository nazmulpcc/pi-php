<?php

declare(strict_types=1);

require_once __DIR__.'/../../coding-agent/tests/TestHelper.php';

use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntime;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\CodingAgent\Session\InMemorySessionStore;
use Pi\Console\ReplHistoryExtractor;
use Pi\Console\ReplPromptRenderer;
use Pi\Console\ReplStartupSummary;

use function Pi\AI\fauxAssistantMessage;
use function Pi\AI\registerFauxProvider;

describe('Repl startup summary', function () {
    it('shows existing plan-mode session details', function () {
        $dir = codingAgentTempDir('repl-summary-existing');
        $runtime = createPersistedSummaryRuntime($dir, 'summary answer');
        codingAgentBlock($runtime->prompt('hello'));
        $runtime->session->sessionManager->appendCustomEntry('plan_mode', ['active' => true]);

        $lines = (new ReplStartupSummary)->lines($runtime);
        $display = implode("\n", $lines);

        codingAgentDeleteDir($dir);

        expect($display)->toContain('Session: '.$runtime->getState()->sessionId.' (existing)');
        expect($display)->toContain('Plan:    active');
        expect($display)->toContain('bash enabled');
    });

    it('shows new memory-only session details', function () {
        $provider = registerFauxProvider([
            'provider' => 'faux',
            'api' => 'faux',
        ]);
        $provider->setResponses([fauxAssistantMessage('summary answer')]);
        $model = $provider->getModel();
        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            cwd: getcwd() ?: '.',
            model: $model,
            provider: 'faux',
            modelId: $model?->id,
            sessionStore: new InMemorySessionStore,
        ));

        $lines = (new ReplStartupSummary)->lines($runtime);
        $display = implode("\n", $lines);

        expect($display)->toContain('Session: '.$runtime->getState()->sessionId.' (new)');
        expect($display)->toContain('File:    memory-only');
        expect($display)->toContain('Plan:    off');
    });

    it('renders the repl prompt from current model and thinking state', function () {
        $provider = registerFauxProvider([
            'provider' => 'faux-prompt',
            'api' => 'faux-prompt',
        ]);
        $provider->setResponses([fauxAssistantMessage('prompt answer')]);
        $model = $provider->getModel();
        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            cwd: getcwd() ?: '.',
            model: $model,
            provider: 'faux-prompt',
            modelId: $model?->id,
            sessionStore: new InMemorySessionStore,
        ));

        expect((new ReplPromptRenderer)->render($runtime->getState()))
            ->toBe(sprintf('pi:%s medium > ', $model?->id));
    });

    it('extracts prior user messages for repl history', function () {
        $dir = codingAgentTempDir('repl-history');
        $runtime = createPersistedSummaryRuntime($dir, 'history answer');
        codingAgentBlock($runtime->prompt('first message'));
        codingAgentBlock($runtime->prompt('second message'));

        $history = (new ReplHistoryExtractor)->userMessages($runtime->getState()->messages);

        codingAgentDeleteDir($dir);

        expect($history)->toContain('first message');
        expect($history)->toContain('second message');
    });
});

function createPersistedSummaryRuntime(string $cwd, string $response): CodingAgentRuntime
{
    $provider = registerFauxProvider([
        'provider' => 'faux',
        'api' => 'faux',
    ]);
    $provider->setResponses([fauxAssistantMessage($response)]);
    $model = $provider->getModel();

    return (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
        cwd: $cwd,
        model: $model,
        provider: 'faux',
        modelId: $model?->id,
        sessionStore: new FilesystemSessionStore($cwd.'/.pi/sessions'),
    ));
}
