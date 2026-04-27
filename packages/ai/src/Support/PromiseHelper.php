<?php

declare(strict_types=1);

namespace Pi\AI\Support;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class PromiseHelper
{
    public static function resolve(mixed $value): PromiseInterface
    {
        return $value instanceof PromiseInterface ? $value : resolve($value);
    }

    public static function reject(\Throwable $error): PromiseInterface
    {
        return reject($error);
    }

    public static function start(callable $task, ?callable $onRejected = null): void
    {
        Loop::futureTick(function () use ($task, $onRejected): void {
            try {
                self::resolve($task())->then(
                    null,
                    static function (mixed $rejected) use ($onRejected): void {
                        if ($onRejected !== null) {
                            $onRejected(self::normalizeThrowable($rejected));
                        }
                    },
                );
            } catch (\Throwable $error) {
                if ($onRejected !== null) {
                    $onRejected($error);
                }
            }
        });
    }

    public static function normalizeThrowable(mixed $value): \Throwable
    {
        if ($value instanceof \Throwable) {
            return $value;
        }

        if (is_string($value)) {
            return new \RuntimeException($value);
        }

        return new \RuntimeException('Promise rejected with a non-throwable value.');
    }
}
