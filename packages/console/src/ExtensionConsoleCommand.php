<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Extension\Extension;
use Pi\CodingAgent\Extension\ExtensionFlag;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ExtensionConsoleCommand extends Command
{
    /**
     * @param  array<Extension>  $extensions
     * @param  array<ExtensionFlag>  $flags
     */
    public function __construct(
        private readonly string $extensionCommandName,
        private readonly string $descriptionText,
        private readonly array $extensions,
        private readonly array $flags = [],
    ) {
        parent::__construct($extensionCommandName);
    }

    protected function configure(): void
    {
        $this
            ->setName($this->extensionCommandName)
            ->setDescription($this->descriptionText)
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override')
            ->addArgument('arguments', InputArgument::IS_ARRAY, 'Extension command arguments');

        foreach ($this->flags as $flag) {
            $mode = $flag->type === 'boolean' ? InputOption::VALUE_NONE : InputOption::VALUE_REQUIRED;
            $default = $flag->type === 'boolean' ? null : $flag->default;
            $this->addOption($flag->name, null, $mode, $flag->description, $default);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwdOption = $input->getOption('cwd');
        $cwd = is_string($cwdOption) && $cwdOption !== '' ? $cwdOption : (getcwd() ?: '.');
        $arguments = implode(' ', array_map('strval', $input->getArgument('arguments')));

        $command = new MainCommand($this->extensions);
        $flagValues = [];
        foreach ($this->flags as $flag) {
            $value = $input->getOption($flag->name);
            if ($flag->type === 'boolean') {
                $flagValues[$flag->name] = (bool) $value;

                continue;
            }
            if (is_string($value) && $value !== '') {
                $flagValues[$flag->name] = $value;
            } elseif ($flag->default !== null) {
                $flagValues[$flag->name] = $flag->default;
            }
        }
        $runtime = $command->createRuntimeFromCwd(
            $cwd,
            new ParsedInput(
                mode: null,
                provider: null,
                modelId: null,
                apiKey: null,
                systemPrompt: null,
                appendSystemPrompt: [],
                thinkingLevel: null,
                continueLatest: false,
                resume: false,
                sessionTarget: null,
                noSession: false,
                sessionDir: null,
                allowedToolNames: null,
                enableContextFiles: true,
                cwd: $cwd,
                messages: [],
                fileArgs: [],
                fileText: '',
                fileImages: [],
                extensionFlagValues: $flagValues,
            ),
        );
        $runner = $runtime->getExtensionRunner();
        if ($runner === null) {
            throw new \RuntimeException('Extension runner is not available.');
        }

        $result = $runner->executeCommand($this->extensionCommandName, $arguments, true);
        if ($result !== null) {
            fwrite(STDOUT, rtrim((string) $result)."\n");
        }

        return 0;
    }
}
