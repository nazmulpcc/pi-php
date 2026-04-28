<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

use Pi\CodingAgent\Config;

final class ExtensionPackageManager
{
    /** @var list<ExtensionPackageDiagnostic> */
    private array $diagnostics = [];

    private readonly ExtensionPackageInventoryStore $projectStore;

    private readonly ExtensionPackageInventoryStore $globalStore;

    public function __construct(
        private readonly string $cwd,
        ?string $agentDir = null,
        ?ExtensionPackageInventoryStore $projectStore = null,
        ?ExtensionPackageInventoryStore $globalStore = null,
        private readonly ?ExtensionPackageManifestReader $manifestReader = null,
    ) {
        $agentDir ??= Config::getAgentDir();
        $this->projectStore = $projectStore ?? new FileExtensionPackageInventoryStore(rtrim($this->cwd, '/').'/'.Config::CONFIG_DIR_NAME.'/packages.json');
        $this->globalStore = $globalStore ?? new FileExtensionPackageInventoryStore(rtrim($agentDir, '/').'/packages.json');
    }

    /**
     * @return list<ExtensionPackageDiagnostic>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return list<ExtensionPackageRecord>
     */
    public function listInstalledPackages(?string $scope = null): array
    {
        $packages = [];
        foreach ($this->scopes($scope) as $resolvedScope) {
            $packages = [...$packages, ...$this->loadInventory($resolvedScope)->packages];
        }

        return $packages;
    }

    public function install(
        string $sourceType,
        string $source,
        string $scope = ExtensionPackageScope::PROJECT,
        ?string $versionOrRef = null,
    ): ExtensionPackageRecord {
        $sourceType = ExtensionPackageSourceType::assertValid($sourceType);
        $scope = ExtensionPackageScope::assertValid($scope);
        $manifest = match ($sourceType) {
            ExtensionPackageSourceType::LOCAL, ExtensionPackageSourceType::COMPOSER => $this->manifestReader()->read($this->resolvePath($source, $this->cwd)),
            ExtensionPackageSourceType::GIT => null,
        };
        $installedPath = $this->installPath(
            $scope,
            $source,
            $sourceType,
            $manifest?->name,
        );
        $this->deletePath($installedPath);

        if ($sourceType === ExtensionPackageSourceType::GIT) {
            $stagingPath = $installedPath.'.tmp-'.substr(md5($source.microtime(true)), 0, 8);
            $this->deletePath($stagingPath);
            $this->cloneGitSource($source, $stagingPath, $versionOrRef);
            $manifest = $this->manifestReader()->read($stagingPath);
            $finalPath = $this->installPath($scope, $source, $sourceType, $manifest->name);
            $this->deletePath($finalPath);
            if (! is_dir(dirname($finalPath))) {
                mkdir(dirname($finalPath), 0777, true);
            }
            rename($stagingPath, $finalPath);
            $installedPath = $finalPath;
        } else {
            $this->copySource($source, $installedPath);
        }

        $manifest ??= $this->manifestReader()->read($installedPath);
        $record = new ExtensionPackageRecord(
            id: $this->packageId($manifest->name, $source),
            name: $manifest->name,
            scope: $scope,
            sourceType: $sourceType,
            source: $source,
            installedPath: $installedPath,
            enabled: true,
            managed: true,
            versionOrRef: $versionOrRef,
            extensions: $manifest->extensions,
            skills: $manifest->skills,
            prompts: $manifest->prompts,
            themes: $manifest->themes,
        );

        $inventory = $this->loadInventory($scope);
        $packages = array_values(array_filter(
            $inventory->packages,
            static fn (ExtensionPackageRecord $candidate): bool => $candidate->id !== $record->id,
        ));
        $packages[] = $record;
        usort($packages, static fn (ExtensionPackageRecord $a, ExtensionPackageRecord $b): int => strcmp($a->id, $b->id));
        $this->saveInventory($scope, new ExtensionPackageInventory($packages));

        return $record;
    }

    public function remove(string $id, ?string $scope = null): void
    {
        [$record, $resolvedScope] = $this->findPackage($id, $scope);
        if (! $record instanceof ExtensionPackageRecord || $resolvedScope === null) {
            throw new \RuntimeException(sprintf('Managed extension package not found: %s', $id));
        }

        $inventory = $this->loadInventory($resolvedScope);
        $packages = array_values(array_filter(
            $inventory->packages,
            static fn (ExtensionPackageRecord $candidate): bool => $candidate->id !== $record->id,
        ));
        $this->saveInventory($resolvedScope, new ExtensionPackageInventory($packages));

        if (is_dir($record->installedPath)) {
            $this->deleteDirectory($record->installedPath);
        } elseif (is_file($record->installedPath)) {
            unlink($record->installedPath);
        }
    }

    public function setEnabled(string $id, bool $enabled, ?string $scope = null): ExtensionPackageRecord
    {
        [$record, $resolvedScope] = $this->findPackage($id, $scope);
        if (! $record instanceof ExtensionPackageRecord || $resolvedScope === null) {
            throw new \RuntimeException(sprintf('Managed extension package not found: %s', $id));
        }

        $updated = new ExtensionPackageRecord(
            id: $record->id,
            name: $record->name,
            scope: $record->scope,
            sourceType: $record->sourceType,
            source: $record->source,
            installedPath: $record->installedPath,
            enabled: $enabled,
            managed: $record->managed,
            versionOrRef: $record->versionOrRef,
            extensions: $record->extensions,
            skills: $record->skills,
            prompts: $record->prompts,
            themes: $record->themes,
        );

        $inventory = $this->loadInventory($resolvedScope);
        $packages = array_map(
            static fn (ExtensionPackageRecord $candidate): ExtensionPackageRecord => $candidate->id === $updated->id ? $updated : $candidate,
            $inventory->packages,
        );
        $this->saveInventory($resolvedScope, new ExtensionPackageInventory($packages));

        return $updated;
    }

    public function update(string $id, ?string $scope = null, ?string $source = null, ?string $versionOrRef = null): ExtensionPackageRecord
    {
        [$record, $resolvedScope] = $this->findPackage($id, $scope);
        if (! $record instanceof ExtensionPackageRecord || $resolvedScope === null) {
            throw new \RuntimeException(sprintf('Managed extension package not found: %s', $id));
        }

        return $this->install(
            $record->sourceType,
            $source ?? $record->source,
            $resolvedScope,
            $versionOrRef ?? $record->versionOrRef,
        );
    }

    public function resolveManagedResources(): ExtensionPackageResolution
    {
        $extensionPaths = [];
        $skillPaths = [];
        $promptPaths = [];
        $themePaths = [];

        foreach ([ExtensionPackageScope::PROJECT, ExtensionPackageScope::GLOBAL] as $scope) {
            foreach ($this->loadInventory($scope)->packages as $record) {
                if (! $record->enabled) {
                    continue;
                }

                $extensionPaths = [...$extensionPaths, ...$this->resolveEntries($record->installedPath, $record->extensions)];
                $skillPaths = [...$skillPaths, ...$this->resolveEntries($record->installedPath, $record->skills)];
                $promptPaths = [...$promptPaths, ...$this->resolveEntries($record->installedPath, $record->prompts)];
                $themePaths = [...$themePaths, ...$this->resolveEntries($record->installedPath, $record->themes)];
            }
        }

        return new ExtensionPackageResolution(
            extensionPaths: $this->uniquePaths($extensionPaths),
            skillPaths: $this->uniquePaths($skillPaths),
            promptPaths: $this->uniquePaths($promptPaths),
            themePaths: $this->uniquePaths($themePaths),
        );
    }

    private function loadInventory(string $scope): ExtensionPackageInventory
    {
        $store = $this->store($scope);

        try {
            $contents = $store->load();
            if ($contents === null || trim($contents) === '') {
                return new ExtensionPackageInventory;
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Extension package inventory must decode to an object.');
            }

            return ExtensionPackageInventory::fromArray($decoded);
        } catch (\Throwable $error) {
            $this->diagnostics[] = new ExtensionPackageDiagnostic(
                sprintf('Failed to load %s package inventory: %s', $scope, $error->getMessage()),
                'error',
                $scope,
                $store->getPath(),
            );

            return new ExtensionPackageInventory;
        }
    }

    private function saveInventory(string $scope, ExtensionPackageInventory $inventory): void
    {
        $this->store($scope)->save(json_encode($inventory->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function store(string $scope): ExtensionPackageInventoryStore
    {
        return $scope === ExtensionPackageScope::GLOBAL ? $this->globalStore : $this->projectStore;
    }

    /**
     * @return array{0:?ExtensionPackageRecord,1:?string}
     */
    private function findPackage(string $id, ?string $scope = null): array
    {
        foreach ($this->scopes($scope) as $resolvedScope) {
            foreach ($this->loadInventory($resolvedScope)->packages as $record) {
                if ($record->id === $id) {
                    return [$record, $resolvedScope];
                }
            }
        }

        return [null, null];
    }

    /**
     * @return list<string>
     */
    private function scopes(?string $scope = null): array
    {
        if ($scope !== null) {
            return [ExtensionPackageScope::assertValid($scope)];
        }

        return [ExtensionPackageScope::PROJECT, ExtensionPackageScope::GLOBAL];
    }

    private function installPath(string $scope, string $source, string $sourceType, ?string $packageName = null): string
    {
        $base = $this->installBasePath($scope);
        $identifier = $this->packageId($packageName ?? basename(rtrim($source, '/')), $sourceType.':'.$source);

        return rtrim($base, '/').'/'.$identifier;
    }

    private function installBasePath(string $scope): string
    {
        return $scope === ExtensionPackageScope::GLOBAL
            ? dirname($this->globalStore->getPath()).'/packages'
            : rtrim($this->cwd, '/').'/'.Config::CONFIG_DIR_NAME.'/packages';
    }

    private function packageId(string $name, string $fallback): string
    {
        $value = strtolower(trim($name !== '' ? $name : $fallback));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = trim($value, '-.');

        return $value !== '' ? $value : 'package-'.substr(md5($fallback), 0, 12);
    }

    private function copySource(string $source, string $installedPath): void
    {
        $resolved = $this->resolvePath($source, $this->cwd);
        if (is_file($resolved)) {
            mkdir($installedPath, 0777, true);
            copy($resolved, rtrim($installedPath, '/').'/'.basename($resolved));

            return;
        }

        if (! is_dir($resolved)) {
            throw new \RuntimeException(sprintf('Extension package source not found: %s', $source));
        }

        $this->copyDirectory($resolved, $installedPath);
    }

    private function cloneGitSource(string $source, string $installedPath, ?string $versionOrRef): void
    {
        $parent = dirname($installedPath);
        if (! is_dir($parent)) {
            mkdir($parent, 0777, true);
        }

        $this->runCommand(
            sprintf(
                'git clone %s %s',
                escapeshellarg($source),
                escapeshellarg($installedPath),
            ),
            $this->cwd,
        );

        if ($versionOrRef !== null && $versionOrRef !== '') {
            $this->runCommand(
                sprintf('git checkout %s', escapeshellarg($versionOrRef)),
                $installedPath,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function resolveEntries(string $basePath, array $entries): array
    {
        $resolved = [];
        foreach ($entries as $entry) {
            $path = str_starts_with($entry, '/')
                ? $entry
                : rtrim($basePath, '/').'/'.ltrim($entry, '/');
            $resolved[] = $path;
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function uniquePaths(array $paths): array
    {
        $seen = [];
        $resolved = [];
        foreach ($paths as $path) {
            $key = realpath($path) ?: $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $resolved[] = $path;
        }

        return $resolved;
    }

    private function manifestReader(): ExtensionPackageManifestReader
    {
        return $this->manifestReader ?? new ExtensionPackageManifestReader;
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

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination.'/'.substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0777, true);
                }

                continue;
            }

            $parent = dirname($target);
            if (! is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            copy($item->getPathname(), $target);
        }
    }

    private function deletePath(string $path): void
    {
        if (is_dir($path)) {
            $this->deleteDirectory($path);

            return;
        }

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function deleteDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private function runCommand(string $command, string $cwd): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (! is_resource($process)) {
            throw new \RuntimeException(sprintf('Unable to execute command: %s', $command));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException(trim(sprintf(
                "Command failed (%d): %s\n%s\n%s",
                $exitCode,
                $command,
                is_string($stdout) ? $stdout : '',
                is_string($stderr) ? $stderr : '',
            )));
        }
    }
}
