<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\Resource\FilesystemResourceLoader;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\CodingAgent\Settings\SettingsManager;

final readonly class ConsoleContext
{
    public function __construct(
        public string $cwd,
        public SettingsManager $settingsManager,
        public AuthStorage $authStorage,
        public FilesystemSessionStore $sessionStore,
        public FilesystemResourceLoader $resourceLoader,
    ) {}
}
