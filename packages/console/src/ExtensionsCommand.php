<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Extension\Package\ExtensionPackageManager;
use Pi\CodingAgent\Extension\Package\ExtensionPackageRecord;
use Pi\CodingAgent\Extension\Package\ExtensionPackageScope;
use Pi\CodingAgent\Extension\Package\ExtensionPackageSourceType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ExtensionsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('extensions')
            ->setDescription('List and manage installed extension packages.')
            ->addArgument('action', InputArgument::OPTIONAL, 'list, install, remove, enable, disable, or update', 'list')
            ->addArgument('target', InputArgument::OPTIONAL, 'Package id or source path/url depending on action')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Use global scope')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Scope override: project or global')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Source type: auto, local, git, or composer', 'auto')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Version/ref for git installs and updates')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Replacement source for update')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON output for list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = (new ConsoleContextFactory)->create(is_string($input->getOption('cwd')) ? $input->getOption('cwd') : null);
        $manager = $context->packageManager;
        $action = (string) $input->getArgument('action');

        try {
            return match ($action) {
                'list' => $this->listPackages($output, $manager, $input),
                'install' => $this->installPackage($io, $manager, $input, $context->cwd),
                'remove' => $this->removePackage($io, $manager, $input),
                'enable' => $this->togglePackage($io, $manager, $input, true),
                'disable' => $this->togglePackage($io, $manager, $input, false),
                'update' => $this->updatePackage($io, $manager, $input),
                default => throw new \RuntimeException('Supported usage: pi extensions list|install|remove|enable|disable|update'),
            };
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }

    private function listPackages(OutputInterface $output, ExtensionPackageManager $manager, InputInterface $input): int
    {
        $scope = $this->resolveScope($input, true);
        $packages = $manager->listInstalledPackages($scope);
        $diagnostics = $manager->getDiagnostics();

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'packages' => array_map(
                    static fn (ExtensionPackageRecord $record): array => $record->toArray(),
                    $packages,
                ),
                'diagnostics' => $diagnostics,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['id', 'name', 'scope', 'sourceType', 'enabled', 'versionOrRef', 'installedPath']);
        foreach ($packages as $record) {
            $table->addRow([
                $record->id,
                $record->name,
                $record->scope,
                $record->sourceType,
                $record->enabled ? 'yes' : 'no',
                $record->versionOrRef ?? '',
                $record->installedPath,
            ]);
        }
        $table->render();
        (new DiagnosticsRenderer)->render($output, $diagnostics, 'Extension package diagnostics');

        return Command::SUCCESS;
    }

    private function installPackage(SymfonyStyle $io, ExtensionPackageManager $manager, InputInterface $input, string $cwd): int
    {
        $target = $this->requireTarget($input, 'pi extensions install requires a source path or URL.');
        $scope = $this->resolveScope($input);
        $sourceType = $this->resolveSourceType((string) $input->getOption('type'), $target, $cwd);
        $record = $manager->install($sourceType, $target, $scope, $this->nullableStringOption($input, 'ref'));

        $io->success(sprintf('Installed %s (%s)', $record->id, $record->scope));
        $io->writeln($record->installedPath);
        (new DiagnosticsRenderer)->render($io, $manager->getDiagnostics(), 'Extension package diagnostics');

        return Command::SUCCESS;
    }

    private function removePackage(SymfonyStyle $io, ExtensionPackageManager $manager, InputInterface $input): int
    {
        $target = $this->requireTarget($input, 'pi extensions remove requires a package id.');
        $scope = $this->resolveScope($input);
        $manager->remove($target, $scope);

        $io->success(sprintf('Removed %s', $target));
        (new DiagnosticsRenderer)->render($io, $manager->getDiagnostics(), 'Extension package diagnostics');

        return Command::SUCCESS;
    }

    private function togglePackage(SymfonyStyle $io, ExtensionPackageManager $manager, InputInterface $input, bool $enabled): int
    {
        $target = $this->requireTarget($input, sprintf('pi extensions %s requires a package id.', $enabled ? 'enable' : 'disable'));
        $scope = $this->resolveScope($input);
        $record = $manager->setEnabled($target, $enabled, $scope);

        $io->success(sprintf('%s %s', $enabled ? 'Enabled' : 'Disabled', $record->id));
        (new DiagnosticsRenderer)->render($io, $manager->getDiagnostics(), 'Extension package diagnostics');

        return Command::SUCCESS;
    }

    private function updatePackage(SymfonyStyle $io, ExtensionPackageManager $manager, InputInterface $input): int
    {
        $target = $this->requireTarget($input, 'pi extensions update requires a package id.');
        $scope = $this->resolveScope($input);
        $record = $manager->update(
            $target,
            $scope,
            $this->nullableStringOption($input, 'source'),
            $this->nullableStringOption($input, 'ref'),
        );

        $io->success(sprintf('Updated %s', $record->id));
        $io->writeln($record->installedPath);
        (new DiagnosticsRenderer)->render($io, $manager->getDiagnostics(), 'Extension package diagnostics');

        return Command::SUCCESS;
    }

    private function resolveScope(InputInterface $input, bool $allowAll = false): ?string
    {
        if ((bool) $input->getOption('global')) {
            return ExtensionPackageScope::GLOBAL;
        }

        $scope = $this->nullableStringOption($input, 'scope');
        if ($scope === null) {
            return $allowAll ? null : ExtensionPackageScope::PROJECT;
        }

        return ExtensionPackageScope::assertValid($scope);
    }

    private function resolveSourceType(string $type, string $target, string $cwd): string
    {
        if ($type !== 'auto') {
            return ExtensionPackageSourceType::assertValid($type);
        }

        $resolvedTarget = $this->resolvePath($target, $cwd);

        if (is_file($resolvedTarget) || is_dir($resolvedTarget)) {
            return ExtensionPackageSourceType::LOCAL;
        }

        if (preg_match('/(^git@|^https?:\/\/.*\.git$|^ssh:\/\/|^[^\/\s]+:[^\/\s].*\.git$)/i', $target) === 1) {
            return ExtensionPackageSourceType::GIT;
        }

        return ExtensionPackageSourceType::LOCAL;
    }

    private function resolvePath(string $path, string $cwd): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: '';

            return rtrim($home, '/').'/'.substr($path, 2);
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($cwd, '/').'/'.$path;
    }

    private function requireTarget(InputInterface $input, string $message): string
    {
        $target = (string) $input->getArgument('target');
        if ($target === '') {
            throw new \RuntimeException($message);
        }

        return $target;
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
