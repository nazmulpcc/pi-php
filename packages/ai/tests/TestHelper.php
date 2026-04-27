<?php

declare(strict_types=1);

use React\Promise\PromiseInterface;
use React\EventLoop\Loop;

use function Pi\AI\packageRoot;

function aiPackageRoot(string $path = ''): string
{
    return packageRoot($path);
}

if (! function_exists('block')) {
    function block(PromiseInterface $promise): mixed
    {
        $value = null;
        $error = null;
        $settled = false;
        $loop = Loop::get();
        $timer = null;

        $promise->then(
            function ($resolved) use (&$value, &$settled, $loop, &$timer): void {
                $value = $resolved;
                $settled = true;
                if ($timer !== null) {
                    Loop::cancelTimer($timer);
                }
                $loop->stop();
            },
            function ($rejected) use (&$error, &$settled, $loop, &$timer): void {
                $error = $rejected;
                $settled = true;
                if ($timer !== null) {
                    Loop::cancelTimer($timer);
                }
                $loop->stop();
            },
        );

        if (! $settled) {
            $timer = Loop::addTimer(5.0, function () use (&$settled, &$error, $loop): void {
                if ($settled) {
                    return;
                }

                $settled = true;
                $error = new RuntimeException('Promise did not settle before timeout');
                $loop->stop();
            });

            $loop->run();
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }
}
