<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Auth;

use Pi\AI\Support\PromiseHelper;
use React\Promise\PromiseInterface;

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

    public function withLockAsync(callable $fn): PromiseInterface
    {
        return PromiseHelper::resolve($fn($this->value))
            ->then(function (array $result): mixed {
                if (array_key_exists('next', $result)) {
                    $this->value = $result['next'];
                }

                return $result['result'];
            });
    }
}
