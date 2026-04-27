<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Auth;

use React\Promise\PromiseInterface;

interface AuthStorageBackend
{
    /**
     * @template T
     *
     * @param  callable(?string): array{result: T, next?: ?string}  $fn
     * @return T
     */
    public function withLock(callable $fn): mixed;

    /**
     * @template T
     *
     * @param  callable(?string): PromiseInterface<array{result: T, next?: ?string}>|array{result: T, next?: ?string}  $fn
     * @return PromiseInterface<T>
     */
    public function withLockAsync(callable $fn): PromiseInterface;
}
