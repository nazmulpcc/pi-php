<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Auth;

final class InMemoryAuthStorageBackend implements AuthStorageBackend
{
    private ?string $value = null;

    public function withLock(callable $fn): mixed
    {
        $result = $fn($this->value);
        if (array_key_exists('next', $result)) {
            $this->value = $result['next'];
        }

        return $result['result'];
    }
}
