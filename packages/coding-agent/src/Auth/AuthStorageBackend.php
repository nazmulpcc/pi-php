<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Auth;

interface AuthStorageBackend
{
    /**
     * @template T
     *
     * @param  callable(?string): array{result: T, next?: ?string}  $fn
     * @return T
     */
    public function withLock(callable $fn): mixed;
}
