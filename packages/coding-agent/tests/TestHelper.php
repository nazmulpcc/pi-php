<?php

declare(strict_types=1);

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

if (! function_exists('codingAgentBlock')) {
    function codingAgentBlock(PromiseInterface $promise): mixed
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
            $timer = Loop::addTimer(10.0, function () use (&$settled, &$error, $loop): void {
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

if (! function_exists('codingAgentTempDir')) {
    function codingAgentTempDir(string $prefix = 'coding-agent-test'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return $path;
    }
}

if (! function_exists('codingAgentDeleteDir')) {
    function codingAgentDeleteDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
