<?php

declare(strict_types=1);

namespace Pi\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ResourcesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('resources')
            ->setDescription('Show discovered prompts, context files, and skills.')
            ->addArgument('action', InputArgument::OPTIONAL, 'Only "show" is supported.', 'show')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((string) $input->getArgument('action') !== 'show') {
            throw new \RuntimeException('Supported usage: pi resources show');
        }

        $context = (new ConsoleContextFactory)->create(is_string($input->getOption('cwd')) ? $input->getOption('cwd') : null);
        $loader = $context->resourceLoader;
        $payload = [
            'systemPrompt' => $loader->getSystemPrompt(),
            'appendSystemPrompt' => $loader->getAppendSystemPrompt(),
            'contextFiles' => array_map(static fn (object $file): array => ['path' => $file->path], $loader->loadContextFiles($context->cwd)),
            'skills' => array_map(static fn (object $skill): array => ['name' => $skill->name, 'path' => $skill->path], $loader->loadSkills($context->cwd)),
            'promptTemplates' => array_map(static fn (object $template): array => ['name' => $template->name, 'path' => $template->path], $loader->loadPromptTemplates($context->cwd)),
            'diagnostics' => $loader->getDiagnostics(),
        ];

        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return 0;
    }
}
