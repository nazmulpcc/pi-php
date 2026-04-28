<?php

declare(strict_types=1);

namespace Pi\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LogoutCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('logout')
            ->setDescription('Remove stored credentials for a provider.')
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = (string) $input->getArgument('provider');
        $context = (new ConsoleContextFactory)->create();
        $context->authStorage->logout($provider);
        $io->success(sprintf('Removed stored credentials for %s.', $provider));

        return 0;
    }
}
