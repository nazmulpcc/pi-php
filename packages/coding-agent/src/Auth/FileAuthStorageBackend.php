<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Auth;

final class FileAuthStorageBackend implements AuthStorageBackend
{
    public function __construct(
        private readonly string $authPath,
    ) {}

    public function withLock(callable $fn): mixed
    {
        $this->ensureParentDir();
        $this->ensureFileExists();

        $handle = fopen($this->authPath, 'c+');
        if (! is_resource($handle)) {
            throw new \RuntimeException(sprintf('Unable to open auth storage: %s', $this->authPath));
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock auth storage: %s', $this->authPath));
            }

            $contents = stream_get_contents($handle);
            $current = is_string($contents) && $contents !== '' ? $contents : null;
            rewind($handle);

            $result = $fn($current);
            if (array_key_exists('next', $result)) {
                ftruncate($handle, 0);
                rewind($handle);
                $next = $result['next'];
                if (is_string($next) && $next !== '') {
                    fwrite($handle, $next);
                }
                fflush($handle);
                @chmod($this->authPath, 0600);
            }

            return $result['result'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureParentDir(): void
    {
        $dir = dirname($this->authPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    private function ensureFileExists(): void
    {
        if (! is_file($this->authPath)) {
            file_put_contents($this->authPath, "{}\n");
            @chmod($this->authPath, 0600);
        }
    }
}
