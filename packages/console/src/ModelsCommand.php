<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Session\InMemorySessionStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ModelsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('models')
            ->setDescription('List available models.')
            ->addArgument('action', InputArgument::OPTIONAL, 'Only "list" is supported.', 'list')
            ->addArgument('search', InputArgument::OPTIONAL, 'Optional search filter')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((string) $input->getArgument('action') !== 'list') {
            throw new \RuntimeException('Supported usage: pi models list [search]');
        }

        $context = (new ConsoleContextFactory)->create(is_string($input->getOption('cwd')) ? $input->getOption('cwd') : null);
        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            cwd: $context->cwd,
            sessionStore: new InMemorySessionStore,
            authStorage: $context->authStorage,
            settingsManager: $context->settingsManager,
            resourceLoader: $context->resourceLoader,
        ));
        $search = mb_strtolower((string) $input->getArgument('search'));

        $table = new Table($output);
        $table->setHeaders(['Provider', 'Model', 'API', 'Reasoning', 'Context']);
        foreach ($runtime->session->getAvailableModels() as $model) {
            $label = mb_strtolower($model->provider->value.'/'.$model->id.'/'.$model->name);
            if ($search !== '' && ! str_contains($label, $search)) {
                continue;
            }

            $table->addRow([
                $model->provider->value,
                $model->id,
                $model->api->value,
                $model->reasoning ? 'yes' : 'no',
                $model->contextWindow,
            ]);
        }
        $table->render();

        return 0;
    }
}
