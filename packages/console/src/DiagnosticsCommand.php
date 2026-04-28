<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Extension\Extension;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DiagnosticsCommand extends Command
{
    /**
     * @param  array<Extension>  $extensions
     */
    public function __construct(
        private readonly array $extensions = [],
    ) {
        parent::__construct('diagnostics');
    }

    protected function configure(): void
    {
        $this
            ->setName('diagnostics')
            ->setDescription('Show runtime diagnostics from auth, settings, resources, models, sessions, and extensions.')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwdOption = $input->getOption('cwd');
        $cwd = is_string($cwdOption) && $cwdOption !== '' ? $cwdOption : null;

        $runtime = (new MainCommand($this->extensions))->createRuntimeFromCwd($cwd ?? (getcwd() ?: '.'));
        $diagnostics = $runtime->getDiagnostics();
        if ($diagnostics === []) {
            $output->writeln('No diagnostics.');

            return 0;
        }

        (new DiagnosticsRenderer)->render($output, $diagnostics);

        return 0;
    }
}
