<?php

declare(strict_types=1);

namespace Pi\Agent;

use Generator;

class EventStream
{
    private array $queue = [];

    private array $waiting = [];

    private bool $done = false;

    private mixed $finalResult = null;

    private bool $resultResolved = false;

    public function push(mixed $event): void
    {
        if ($this->done) {
            return;
        }

        if ($this->isComplete($event)) {
            $this->done = true;
            $this->finalResult = $this->extractResult($event);
            $this->resultResolved = true;
        }

        $waiter = array_shift($this->waiting);
        if ($waiter !== null) {
            $waiter($event, false);
        } else {
            $this->queue[] = $event;
        }
    }

    public function end(mixed $result = null): void
    {
        $this->done = true;
        if ($result !== null) {
            $this->finalResult = $result;
            $this->resultResolved = true;
        }

        while (count($this->waiting) > 0) {
            $waiter = array_shift($this->waiting);
            $waiter(null, true);
        }
    }

    public function iterate(): Generator
    {
        while (true) {
            if (count($this->queue) > 0) {
                yield array_shift($this->queue);
            } elseif ($this->done) {
                return;
            } else {
                [$event, $isDone] = yield from $this->waitNext();
                if ($isDone) {
                    return;
                }
                yield $event;
            }
        }
    }

    public function result(): mixed
    {
        if ($this->resultResolved) {
            return $this->finalResult;
        }

        foreach ($this->iterate() as $event) {
        }

        return $this->finalResult;
    }

    private function waitNext(): Generator
    {
        $resolved = false;
        $value = null;
        $done = false;

        $this->waiting[] = function (mixed $v, bool $d) use (&$resolved, &$value, &$done): void {
            $resolved = true;
            $value = $v;
            $done = $d;
        };

        while (! $resolved) {
            yield null;
        }

        return [$value, $done];
    }

    private function isComplete(mixed $event): bool
    {
        return false;
    }

    private function extractResult(mixed $event): mixed
    {
        return null;
    }
}
