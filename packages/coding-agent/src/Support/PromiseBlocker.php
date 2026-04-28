<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Support;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class PromiseBlocker
{
    public static function block(PromiseInterface $promise, ?float $timeoutSeconds = null): mixed
    {
        $value = null;
        $error = null;
        $settled = false;
        $loop = Loop::get();

        $promise->then(
            function ($resolved) use (&$value, &$settled, $loop): void {
                $value = $resolved;
                $settled = true;
                $loop->stop();
            },
            function ($rejected) use (&$error, &$settled, $loop): void {
                $error = $rejected;
                $settled = true;
                $loop->stop();
            },
        );

        if (! $settled) {
            $loop->run();
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }
}
