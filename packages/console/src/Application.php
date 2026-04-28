<?php

declare(strict_types=1);

namespace Pi\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class Application
{
    private readonly SymfonyApplication $application;

    public function __construct()
    {
        $this->application = new SymfonyApplication('pi-console');
        $this->application->setAutoExit(false);
        $this->application->add(new MainCommand);
        $this->application->add(new LoginCommand);
        $this->application->add(new LogoutCommand);
        $this->application->add(new AuthCommand);
        $this->application->add(new SessionsCommand);
        $this->application->add(new SettingsCommand);
        $this->application->add(new ResourcesCommand);
        $this->application->add(new ModelsCommand);
        $this->application->setDefaultCommand('_default', true);
    }

    public function run(array $argv): int
    {
        return $this->application->run(
            new ArgvInput(array_merge(['bin/pi'], $argv)),
            new ConsoleOutput,
        );
    }
}
