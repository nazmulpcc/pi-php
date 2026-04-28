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
        $application->add(new ExtensionsCommand);
        $application->add(new DiagnosticsCommand);
        $knownCommands = ['_default', 'help', 'list', 'login', 'logout', 'auth', 'sessions', 'settings', 'resources', 'models', 'extensions', 'diagnostics'];
        foreach ($mainCommand->getExtensionCommands() as $command) {
            $application->add($command);
            $knownCommands[] = $command->getName();
        }
        $application->setDefaultCommand('_default');

        $normalizedArgv = ['bin/pi', ...$this->normalizeArgv($argv, $knownCommands)];

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
    private function normalizeArgv(array $argv, array $knownCommands): array
    {
        $commandInfo = $this->findCommandToken($argv);
        $first = $commandInfo['token'];

        if (is_string($first) && $first !== '' && in_array($first, $knownCommands, true)) {
            $index = $commandInfo['index'];
            if ($index === 0) {
                return in_array($first, ['help', 'list'], true)
                    ? $this->stripCwdOptions($argv)
                    : $argv;
            }

            if (is_int($index) && $index > 0) {
                $normalized = [
                    $first,
                    ...array_slice($argv, 0, $index),
                    ...array_slice($argv, $index + 1),
                ];

                return in_array($first, ['help', 'list'], true)
                    ? $this->stripCwdOptions($normalized)
                    : $normalized;
            }
        }

        return ['_default', ...$argv];
    }

    /**
     * @param  list<string>  $argv
     */
    private function findCommandToken(array $argv): array
    {
        for ($index = 0, $count = count($argv); $index < $count; $index++) {
            $argument = $argv[$index];
            if (! is_string($argument) || $argument === '') {
                continue;
            }

            if ($argument === '--cwd') {
                $index++;

                continue;
            }

            if (str_starts_with($argument, '--cwd=')) {
                continue;
            }

            if (str_starts_with($argument, '-')) {
                continue;
            }

            return ['token' => $argument, 'index' => $index];
        }

        return ['token' => null, 'index' => null];
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function stripCwdOptions(array $argv): array
    {
        $result = [];

        for ($index = 0, $count = count($argv); $index < $count; $index++) {
            $argument = $argv[$index];
            if ($argument === '--cwd') {
                $index++;

                continue;
            }

            if (is_string($argument) && str_starts_with($argument, '--cwd=')) {
                continue;
            }

            $result[] = $argument;
        }

        return $result;
    }
}
