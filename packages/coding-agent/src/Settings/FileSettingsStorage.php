<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Settings;

use Pi\CodingAgent\Config;

final readonly class FileSettingsStorage implements SettingsStorage
{
    public function __construct(
        private string $cwd,
        private string $agentDir = '',
    ) {}

    public function read(string $scope): ?string
    {
        $path = $this->pathFor($scope);
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    public function write(string $scope, string $content): void
    {
        $path = $this->pathFor($scope);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $handle = fopen($path, 'c+');
        if (! is_resource($handle)) {
            throw new \RuntimeException(sprintf('Unable to open settings file: %s', $path));
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock settings file: %s', $path));
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $content);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function pathFor(string $scope): string
    {
        if ($scope === 'global') {
            $agentDir = $this->agentDir !== '' ? $this->agentDir : Config::getAgentDir();

            return rtrim($agentDir, '/').'/settings.json';
        }

        return rtrim($this->cwd, '/').'/'.Config::CONFIG_DIR_NAME.'/settings.json';
    }
}
