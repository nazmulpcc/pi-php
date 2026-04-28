<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Extension\ExtensionLoader;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class Application
{
    public function run(array $argv): int
    {
        $cwd = $this->extractCwd($argv);
        $context = (new ConsoleContextFactory)->create($cwd);
        $extensions = (new ExtensionLoader)->discover($context->cwd, $context->settingsManager)->extensions;
        $mainCommand = new MainCommand($extensions);
        $application = new SymfonyApplication('pi-console');
        $application->setAutoExit(false);
        $application->add($mainCommand);
        $application->add(new LoginCommand);
        $application->add(new LogoutCommand);
        $application->add(new AuthCommand);
        $application->add(new SessionsCommand);
        $application->add(new SettingsCommand);
        $application->add(new ResourcesCommand);
        $application->add(new ModelsCommand);
        $knownCommands = ['_default', 'login', 'logout', 'auth', 'sessions', 'settings', 'resources', 'models'];
        foreach ($mainCommand->getExtensionCommands() as $command) {
            $application->add($command);
            $knownCommands[] = $command->getName();
        }
        $application->setDefaultCommand('_default');

        $normalizedArgv = ['bin/pi'];
        if (! $this->startsWithKnownCommand($argv, $knownCommands)) {
            $normalizedArgv[] = '_default';
        }
        $normalizedArgv = [...$normalizedArgv, ...$argv];

        return $application->run(
            new ArgvInput($normalizedArgv),
            new ConsoleOutput,
        );
    }

    /**
     * @param  list<string>  $argv
     */
    private function extractCwd(array $argv): string
    {
        foreach ($argv as $index => $argument) {
            if ($argument === '--cwd') {
                $value = $argv[$index + 1] ?? null;

                return is_string($value) && $value !== '' ? $value : (getcwd() ?: '.');
            }

            if (str_starts_with($argument, '--cwd=')) {
                $value = substr($argument, 6);

                return $value !== '' ? $value : (getcwd() ?: '.');
            }
        }

        return getcwd() ?: '.';
    }

    /**
     * @param  list<string>  $argv
     * @param  list<string>  $knownCommands
     */
    private function startsWithKnownCommand(array $argv, array $knownCommands): bool
    {
        $first = $argv[0] ?? null;

        return is_string($first) && $first !== '' && ! str_starts_with($first, '-') && in_array($first, $knownCommands, true);
    }
}
