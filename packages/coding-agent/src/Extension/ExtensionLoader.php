<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\CodingAgent\Config;
use Pi\CodingAgent\Settings\SettingsManager;

final class ExtensionLoader
{
    public function discover(string $cwd, SettingsManager $settingsManager): ExtensionLoadResult
    {
        $paths = [];
        $seen = [];
        $diagnostics = [];

        $add = function (string $path) use (&$paths, &$seen): void {
            $resolved = realpath($path) ?: $path;
            if (isset($seen[$resolved])) {
                return;
            }
            $seen[$resolved] = true;
            $paths[] = $path;
        };

        foreach ($this->discoverInDirectory(rtrim($cwd, '/').'/'.Config::CONFIG_DIR_NAME.'/extensions') as $path) {
            $add($path);
        }
        foreach ($this->discoverInDirectory(Config::getAgentDir().'/extensions') as $path) {
            $add($path);
        }
        foreach ($settingsManager->getExtensionPaths() as $configuredPath) {
            foreach ($this->resolveConfiguredPath($configuredPath, $cwd, $diagnostics) as $path) {
                $add($path);
            }
        }

        $loaded = $this->loadPaths($paths, $cwd);

        return new ExtensionLoadResult($loaded->extensions, [...$diagnostics, ...$loaded->diagnostics]);
    }

    /**
     * @param  list<string>  $paths
     */
    public function loadPaths(array $paths, string $cwd): ExtensionLoadResult
    {
        $extensions = [];
        $diagnostics = [];

        foreach ($paths as $path) {
            $extension = $this->loadPath($path, $cwd, $diagnostics);
            if ($extension instanceof Extension) {
                $extensions[] = $extension;
            }
        }

        return new ExtensionLoadResult($extensions, $diagnostics);
    }

    /**
     * @param  list<ExtensionDiagnostic>  $diagnostics
     */
    private function loadPath(string $path, string $cwd, array &$diagnostics): ?Extension
    {
        $resolvedPath = $this->resolvePath($path, $cwd);
        if (! is_file($resolvedPath)) {
            $diagnostics[] = new ExtensionDiagnostic($path, 'Extension file not found.');

            return null;
        }

        try {
            $factory = require $resolvedPath;
            if (! is_callable($factory)) {
                $diagnostics[] = new ExtensionDiagnostic($path, 'Extension entry file must return a callable factory.');

                return null;
            }

            $extension = new Extension($path, $resolvedPath);
            $runtime = new ExtensionRuntime;
            $api = new ExtensionAPI($extension, $runtime);
            $extension->api = $api;
            $factory($api);

            return $extension;
        } catch (\Throwable $error) {
            $diagnostics[] = new ExtensionDiagnostic($path, $error->getMessage());

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function discoverInDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];
        $entries = scandir($directory);
        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $directory.'/'.$entry;
            if (is_file($entryPath) && str_ends_with(strtolower($entryPath), '.php')) {
                $paths[] = $entryPath;

                continue;
            }

            if (! is_dir($entryPath)) {
                continue;
            }

            foreach ($this->resolveDirectoryEntries($entryPath) as $resolved) {
                $paths[] = $resolved;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param  list<ExtensionDiagnostic>  $diagnostics
     * @return list<string>
     */
    private function resolveConfiguredPath(string $configuredPath, string $cwd, array &$diagnostics): array
    {
        $resolved = $this->resolvePath($configuredPath, $cwd);
        if (is_dir($resolved)) {
            $entries = $this->resolveDirectoryEntries($resolved);
            if ($entries !== []) {
                return $entries;
            }

            return $this->discoverInDirectory($resolved);
        }

        if (is_file($resolved)) {
            return [$resolved];
        }

        $diagnostics[] = new ExtensionDiagnostic($configuredPath, 'Configured extension path not found.', 'warning');

        return [];
    }

    /**
     * @return list<string>
     */
    private function resolveDirectoryEntries(string $directory): array
    {
        $manifestEntries = $this->readComposerManifestEntries($directory);
        if ($manifestEntries !== []) {
            $resolved = [];
            foreach ($manifestEntries as $entry) {
                $path = $directory.'/'.ltrim($entry, '/');
                if (is_file($path)) {
                    $resolved[] = $path;
                }
            }

            return $resolved;
        }

        foreach (['index.php', 'extension.php'] as $entry) {
            $path = $directory.'/'.$entry;
            if (is_file($path)) {
                return [$path];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function readComposerManifestEntries(string $directory): array
    {
        $composerPath = $directory.'/composer.json';
        if (! is_file($composerPath)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        $entries = $decoded['extra']['pi']['extensions'] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_string')) : [];
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
}
