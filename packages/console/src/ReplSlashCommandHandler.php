<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\Agent\ThinkingLevel;
use Pi\CodingAgent\CodingAgentRuntime;

final class ReplSlashCommandHandler
{
    private const BUILT_IN_COMMANDS = [
        '/help',
        '/exit',
        '/quit',
        '/continue',
        '/session',
        '/sessions',
        '/model',
        '/thinking',
        '/auth',
        '/export',
    ];

    public function __construct(
        private readonly ConsoleContextFactory $contextFactory = new ConsoleContextFactory,
    ) {}

    /**
     * @return list<string>
     */
    public function getBuiltInCommands(): array
    {
        return self::BUILT_IN_COMMANDS;
    }

    /**
     * @return array{handled: bool, exit?: bool, output?: string}
     */
    public function handle(string $line, CodingAgentRuntime $runtime): array
    {
        $line = trim($line);
        if (! str_starts_with($line, '/')) {
            return ['handled' => false];
        }

        [$command, $rest] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');

        return match ($command) {
            '/exit', '/quit' => ['handled' => true, 'exit' => true],
            '/help' => ['handled' => true, 'output' => "/help\n/exit\n/quit\n/session\n/sessions\n/model [provider/id|cycle]\n/thinking [low|medium|high|xhigh|cycle]\n/auth\n/export [path]\n"],
            '/continue' => ['handled' => false],
            '/session' => ['handled' => true, 'output' => $this->renderSession($runtime)],
            '/sessions' => ['handled' => true, 'output' => $this->renderSessions($runtime)],
            '/model' => ['handled' => true, 'output' => $this->handleModel($runtime, $rest)],
            '/thinking' => ['handled' => true, 'output' => $this->handleThinking($runtime, $rest)],
            '/auth' => ['handled' => true, 'output' => $this->renderAuth($runtime)],
            '/export' => ['handled' => true, 'output' => $this->handleExport($runtime, $rest)],
            default => ['handled' => false],
        };
    }

    private function renderSession(CodingAgentRuntime $runtime): string
    {
        $state = $runtime->getState();

        return sprintf(
            "session: %s\npath: %s\ncwd: %s\nmodel: %s\nthinking: %s\n",
            $state->sessionId,
            $state->sessionPath ?? '(memory)',
            $state->cwd,
            $state->model === null ? 'unset' : $state->model->provider->value.'/'.$state->model->id,
            $state->thinkingLevel->value,
        );
    }

    private function renderSessions(CodingAgentRuntime $runtime): string
    {
        $context = $this->contextFactory->create($runtime->getState()->cwd);
        $sessions = (new SessionInspector)->list($context->sessionStore);
        if ($sessions === []) {
            return "No persisted sessions found.\n";
        }

        $lines = [];
        foreach (array_slice($sessions, 0, 10) as $session) {
            $lines[] = sprintf('%s  %s  %s', $session['id'], $session['lastTimestamp'], $session['path']);
        }

        return implode("\n", $lines)."\n";
    }

    private function handleModel(CodingAgentRuntime $runtime, string $rest): string
    {
        $rest = trim($rest);
        if ($rest === '') {
            $state = $runtime->getState();

            return sprintf("model: %s\n", $state->model === null ? 'unset' : $state->model->provider->value.'/'.$state->model->id);
        }

        if ($rest === 'cycle') {
            $model = $runtime->session->cycleModel();

            return sprintf("model: %s\n", $model === null ? 'unset' : $model->provider->value.'/'.$model->id);
        }

        [$provider, $modelId] = array_pad(explode('/', $rest, 2), 2, null);
        if (! is_string($provider) || $provider === '' || ! is_string($modelId) || $modelId === '') {
            return "Usage: /model [provider/id|cycle]\n";
        }

        foreach ($runtime->session->getAvailableModels() as $model) {
            if ($model->provider->value === $provider && $model->id === $modelId) {
                $runtime->session->setModel($model);

                return sprintf("model: %s/%s\n", $provider, $modelId);
            }
        }

        return sprintf("Model not found: %s/%s\n", $provider, $modelId);
    }

    private function handleThinking(CodingAgentRuntime $runtime, string $rest): string
    {
        $rest = trim($rest);
        if ($rest === '') {
            return sprintf("thinking: %s\n", $runtime->getState()->thinkingLevel->value);
        }

        if ($rest === 'cycle') {
            return sprintf("thinking: %s\n", $runtime->session->cycleThinkingLevel()->value);
        }

        $runtime->session->setThinkingLevel(ThinkingLevel::from($rest));

        return sprintf("thinking: %s\n", $runtime->getState()->thinkingLevel->value);
    }

    private function renderAuth(CodingAgentRuntime $runtime): string
    {
        $context = $this->contextFactory->create($runtime->getState()->cwd);
        $lines = [];
        foreach ($context->authStorage->getOAuthProviders() as $provider) {
            $status = $context->authStorage->getStatus($provider->getId());
            $lines[] = sprintf(
                '%s: %s%s',
                $provider->getId(),
                ($status['configured'] ?? false) ? 'configured' : 'not configured',
                isset($status['source']) ? ' ('.$status['source'].')' : '',
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function handleExport(CodingAgentRuntime $runtime, string $rest): string
    {
        $state = $runtime->getState();
        if ($state->sessionPath === null) {
            return "Current session is in-memory and cannot be exported.\n";
        }

        $outputPath = trim($rest);
        if ($outputPath === '') {
            $outputPath = preg_replace('/\.jsonl$/', '.html', $state->sessionPath) ?: ($state->sessionPath.'.html');
        }

        $context = $this->contextFactory->create($state->cwd);
        $manager = (new SessionInspector)->resolve($context->sessionStore, $state->sessionPath, $state->cwd);
        (new SessionHtmlExporter)->export($manager, $outputPath);

        return sprintf("exported: %s\n", $outputPath);
    }
}
