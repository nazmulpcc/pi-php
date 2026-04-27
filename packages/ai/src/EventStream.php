<?php

declare(strict_types=1);

namespace Pi\AI;

use Closure;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * @template TEvent
 * @template TResult
 */
class EventStream
{
    /** @var array<int, TEvent> */
    private array $queue = [];

    /** @var array<int, Deferred<TEvent|null>> */
    private array $waiting = [];

    private bool $done = false;

    /** @var Deferred<TResult> */
    private Deferred $resultDeferred;

    private bool $resultResolved = false;

    /**
     * @param  Closure(TEvent): bool  $isComplete
     * @param  Closure(TEvent): TResult  $extractResult
     */
    public function __construct(
        private readonly Closure $isComplete,
        private readonly Closure $extractResult,
    ) {
        $this->resultDeferred = new Deferred;
    }

    /**
     * @param  TEvent  $event
     */
    public function push(mixed $event): void
    {
        if ($this->done) {
            return;
        }

        if (($this->isComplete)($event)) {
            $this->done = true;
            $this->resolveResult(($this->extractResult)($event));
        }

        $waiter = array_shift($this->waiting);
        if ($waiter instanceof Deferred) {
            $waiter->resolve($event);

            return;
        }

        $this->queue[] = $event;
    }

    /**
     * @param  TResult|null  $result
     */
    public function end(mixed $result = null): void
    {
        $this->done = true;

        if ($result !== null) {
            $this->resolveResult($result);
        }

        while ($this->waiting !== []) {
            $waiter = array_shift($this->waiting);
            $waiter?->resolve(null);
        }
    }

    /**
     * @return PromiseInterface<TEvent|null>
     */
    public function next(): PromiseInterface
    {
        if ($this->queue !== []) {
            return resolve(array_shift($this->queue));
        }

        if ($this->done) {
            return resolve(null);
        }

        $deferred = new Deferred;
        $this->waiting[] = $deferred;

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<TResult>
     */
    public function result(): PromiseInterface
    {
        return $this->resultDeferred->promise();
    }

    /**
     * @param  TResult  $result
     */
    private function resolveResult(mixed $result): void
    {
        if ($this->resultResolved) {
            return;
        }

        $this->resultResolved = true;
        $this->resultDeferred->resolve($result);
    }
}
