<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\Extension\Package\ExtensionPackageManager;
use Pi\CodingAgent\Resource\FilesystemResourceLoader;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\CodingAgent\Settings\SettingsManager;

final class ConsoleContextFactory
{
    public function create(?string $cwd = null, ?string $sessionDir = null): ConsoleContext
    {
        $resolvedCwd = is_string($cwd) && $cwd !== '' ? $cwd : (getcwd() ?: '.');
        $settingsManager = SettingsManager::create($resolvedCwd);
        $authStorage = AuthStorage::create();
        $store = new FilesystemSessionStore($sessionDir ?? $settingsManager->getSessionDir($resolvedCwd));
        $resourceLoader = new FilesystemResourceLoader(
            cwd: $resolvedCwd,
            settingsManager: $settingsManager,
        );
        $packageManager = new ExtensionPackageManager($resolvedCwd);
        $managedResources = $packageManager->resolveManagedResources();
        $resourceLoader->extendResources(
            $managedResources->skillPaths,
            $managedResources->promptPaths,
            $managedResources->themePaths,
        );

        return new ConsoleContext(
            cwd: $resolvedCwd,
            settingsManager: $settingsManager,
            authStorage: $authStorage,
            sessionStore: $store,
            resourceLoader: $resourceLoader,
            packageManager: $packageManager,
        );
    }
}
