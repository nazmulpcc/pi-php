<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SessionsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('sessions')
            ->setDescription('List, inspect, export, or fork sessions.')
            ->addArgument('action', InputArgument::OPTIONAL, 'list, show, export, or fork', 'list')
            ->addArgument('target', InputArgument::OPTIONAL, 'Session id, prefix, or path')
            ->addArgument('output', InputArgument::OPTIONAL, 'Output path for export.html')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override')
            ->addOption('session-dir', null, InputOption::VALUE_REQUIRED, 'Session directory override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = (new ConsoleContextFactory)->create(
            is_string($input->getOption('cwd')) ? $input->getOption('cwd') : null,
            is_string($input->getOption('session-dir')) ? $input->getOption('session-dir') : null,
        );
        $inspector = new SessionInspector;
        $action = (string) $input->getArgument('action');

        return match ($action) {
            'list' => $this->listSessions($output, $inspector->list($context->sessionStore)),
            'show' => $this->showSession($io, $inspector, $context, (string) $input->getArgument('target')),
            'export' => $this->exportSession($io, $inspector, $context, (string) $input->getArgument('target'), $input->getArgument('output')),
            'fork' => $this->forkSession($io, $inspector, $context, (string) $input->getArgument('target')),
            default => throw new \RuntimeException('Supported usage: pi sessions list|show|export|fork'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     */
    private function listSessions(OutputInterface $output, array $sessions): int
    {
        $table = new Table($output);
        $table->setHeaders(['Session', 'Model', 'Thinking', 'Messages', 'Updated', 'Path']);
        foreach ($sessions as $session) {
            $table->addRow([
                $session['id'],
                $session['model'] ?? '',
                $session['thinkingLevel'],
                $session['messageCount'],
                $session['lastTimestamp'],
                $session['path'],
            ]);
        }
        $table->render();

        return 0;
    }

    private function showSession(SymfonyStyle $io, SessionInspector $inspector, ConsoleContext $context, string $target): int
    {
        if ($target === '') {
            throw new \RuntimeException('pi sessions show requires a session id, prefix, or path.');
        }

        $summary = $inspector->summarize($inspector->resolve($context->sessionStore, $target, $context->cwd));
        foreach ($summary as $key => $value) {
            $io->writeln(sprintf('%s: %s', $key, is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR)));
        }

        return 0;
    }

    private function exportSession(SymfonyStyle $io, SessionInspector $inspector, ConsoleContext $context, string $target, mixed $outputPath): int
    {
        if ($target === '') {
            throw new \RuntimeException('pi sessions export requires a session id, prefix, or path.');
        }

        $manager = $inspector->resolve($context->sessionStore, $target, $context->cwd);
        $resolvedOutput = is_string($outputPath) && $outputPath !== ''
            ? $outputPath
            : preg_replace('/\.jsonl$/', '.html', (string) $manager->getSessionFile());

        $path = (new SessionHtmlExporter)->export($manager, (string) $resolvedOutput);
        $io->success(sprintf('Exported HTML to %s', $path));

        return 0;
    }

    private function forkSession(SymfonyStyle $io, SessionInspector $inspector, ConsoleContext $context, string $target): int
    {
        if ($target === '') {
            throw new \RuntimeException('pi sessions fork requires a session id, prefix, or path.');
        }

        $manager = $inspector->resolve($context->sessionStore, $target, $context->cwd);
        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            cwd: $manager->getCwd(),
            sessionStore: $context->sessionStore,
            authStorage: $context->authStorage,
            settingsManager: $context->settingsManager,
            resourceLoader: $context->resourceLoader,
        ));
        $runtime->newSession($manager->getSessionId());
        $state = $runtime->getState();
        $runtime->session->sessionManager->save();
        $io->success(sprintf('Forked %s into %s', $manager->getSessionId(), $state->sessionId));
        $io->writeln((string) $state->sessionPath);

        return 0;
    }
}
