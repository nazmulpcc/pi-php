<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

final readonly class FilesystemSessionStore implements SessionStore
{
    public function __construct(
        public string $directory,
    ) {}

    public function createManager(string $cwd, ?string $sessionId = null): SessionManager
    {
        return SessionManager::create($cwd, $this->directory, true, $sessionId);
    }

    public function openManager(string $sessionIdOrPath, ?string $cwd = null): ?SessionManager
    {
        $path = str_contains($sessionIdOrPath, DIRECTORY_SEPARATOR) || str_ends_with($sessionIdOrPath, '.jsonl')
            ? $sessionIdOrPath
            : $this->resolveIdPath($sessionIdOrPath);

        if ($path === null) {
            return null;
        }

        return SessionManager::open($path, $this->directory, $cwd);
    }

    public function continueLatest(string $cwd): ?SessionManager
    {
        return SessionManager::continueRecent($cwd, $this->directory);
    }

    private function resolveIdPath(string $sessionIdOrPrefix): ?string
    {
        $pattern = rtrim($this->directory, '/').'/*.jsonl';
        $matches = glob($pattern);
        if ($matches === false || $matches === []) {
            return null;
        }

        foreach ($matches as $path) {
            $base = basename($path);
            if (preg_match('/^[^_]+_([^\.]+)\.jsonl$/', $base, $parts) === 1) {
                if ($parts[1] === $sessionIdOrPrefix || str_starts_with($parts[1], $sessionIdOrPrefix)) {
                    return $path;
                }
            }
        }

        return null;
    }
}
