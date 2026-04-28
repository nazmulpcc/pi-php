<?php

declare(strict_types=1);

namespace Pi\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AuthCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('auth')
            ->setDescription('Inspect stored authentication state.')
            ->addArgument('action', InputArgument::OPTIONAL, 'Only "status" is supported.', 'status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((string) $input->getArgument('action') !== 'status') {
            throw new \RuntimeException('Supported usage: pi auth status');
        }

        $context = (new ConsoleContextFactory)->create();
        $table = new Table($output);
        $table->setHeaders(['Provider', 'Configured', 'Source', 'Label']);

        $providers = [];
        foreach ($context->authStorage->getOAuthProviders() as $provider) {
            $providers[$provider->getId()] = true;
        }
        foreach ($context->authStorage->list() as $providerId) {
            $providers[$providerId] = true;
        }
        ksort($providers);

        foreach (array_keys($providers) as $providerId) {
            $status = $context->authStorage->getStatus($providerId);
            $table->addRow([
                $providerId,
                ($status['configured'] ?? false) ? 'yes' : 'no',
                $status['source'] ?? '',
                $status['label'] ?? '',
            ]);
        }

        $table->render();

        return 0;
    }
}
