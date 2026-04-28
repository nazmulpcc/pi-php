<?php

declare(strict_types=1);

namespace Pi\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SettingsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('settings')
            ->setDescription('Show or mutate project/global settings.')
            ->addArgument('action', InputArgument::OPTIONAL, 'show or set', 'show')
            ->addArgument('key', InputArgument::OPTIONAL, 'Setting key for set')
            ->addArgument('value', InputArgument::OPTIONAL, 'Setting value for set')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Operate on global settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = (new ConsoleContextFactory)->create(is_string($input->getOption('cwd')) ? $input->getOption('cwd') : null);
        $action = (string) $input->getArgument('action');

        if ($action === 'show') {
            $payload = [
                'global' => $context->settingsManager->getGlobalSettings(),
                'project' => $context->settingsManager->getProjectSettings(),
                'resolved' => $context->settingsManager->getSettings(),
                'errors' => $context->settingsManager->getErrors(),
            ];
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return 0;
        }

        if ($action !== 'set') {
            throw new \RuntimeException('Supported usage: pi settings show|set');
        }

        $key = (string) $input->getArgument('key');
        if ($key === '') {
            throw new \RuntimeException('pi settings set requires a dotted key.');
        }

        $value = (new SettingsValueParser)->parse((string) $input->getArgument('value'));
        $scope = $input->getOption('global') ? 'global' : 'project';
        $context->settingsManager->setValue($scope, $key, $value);

        $output->writeln(sprintf('Updated %s setting %s.', $scope, $key));

        return 0;
    }
}
