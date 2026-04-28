<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Model\ModelRegistry;
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
            ->addOption('usable', null, InputOption::VALUE_NONE, 'Only list models from providers with configured credentials')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((string) $input->getArgument('action') !== 'list') {
            throw new \RuntimeException('Supported usage: pi models list [search]');
        }

        $context = (new ConsoleContextFactory)->create(is_string($input->getOption('cwd')) ? $input->getOption('cwd') : null);
        $registry = new ModelRegistry($context->authStorage, $context->settingsManager);
        $availabilityByProvider = [];
        foreach ($registry->getProviderAvailability() as $availability) {
            $availabilityByProvider[$availability->provider] = $availability;
        }
        (new DiagnosticsRenderer)->render($output, $registry->getDiagnostics(), 'Model diagnostics');
        $search = mb_strtolower((string) $input->getArgument('search'));
        $models = $input->getOption('usable') ? $registry->getUsableModels() : $registry->getAvailableModels();

        $table = new Table($output);
        $table->setHeaders(['Provider', 'Model', 'API', 'Reasoning', 'Context', 'Auth']);
        foreach ($models as $model) {
            $label = mb_strtolower($model->provider->value.'/'.$model->id.'/'.$model->name);
            if ($search !== '' && ! str_contains($label, $search)) {
                continue;
            }

            $availability = $availabilityByProvider[$model->provider->value] ?? null;
            $table->addRow([
                $model->provider->value,
                $model->id,
                $model->api->value,
                $model->reasoning ? 'yes' : 'no',
                $model->contextWindow,
                $availability?->source ?? ($availability?->configured === true ? 'configured' : ''),
            ]);
        }
        $table->render();

        return 0;
    }
}
