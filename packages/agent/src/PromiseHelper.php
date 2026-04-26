<?php

declare(strict_types=1);

namespace Pi\Agent;

use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class PromiseHelper
{
    /**
     * Normalize a mixed value into a PromiseInterface.
     *
     * If the value is already a PromiseInterface, return it as-is.
     * Otherwise, wrap it in a resolved promise.
     */
    public static function resolve(mixed $value): PromiseInterface
    {
        if ($value instanceof PromiseInterface) {
            return $value;
        }

        return resolve($value);
    }

    /**
     * Await an array of mixed values (promises or plain values)
     * and return a promise that resolves with an array of results.
     *
     * @param  array<mixed>  $values
     * @return PromiseInterface<array>
     */
    public static function all(array $values): PromiseInterface
    {
        return \React\Promise\all($values);
    }
}
