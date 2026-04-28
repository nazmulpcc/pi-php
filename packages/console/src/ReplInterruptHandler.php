<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\CodingAgentRuntime;

final class ReplInterruptHandler
{
    private bool $turnActive = false;

    private bool $abortRequested = false;

    private mixed $previousHandler = null;

    private ?bool $previousAsyncSignals = null;

    public function __construct(
        private readonly CodingAgentRuntime $runtime,
        private readonly ConsoleOutputGuard $outputGuard,
    ) {}

    public function install(): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $this->previousAsyncSignals = pcntl_async_signals(true);
        $this->previousHandler = pcntl_signal_get_handler(SIGINT);
        pcntl_signal(SIGINT, function (): void {
            $this->handle();
        });
    }

    public function restore(): void
    {
        if (! $this->isSupported()) {
            return;
        }

        if ($this->previousHandler !== null) {
            pcntl_signal(SIGINT, $this->previousHandler);
        }

        if ($this->previousAsyncSignals !== null) {
            pcntl_async_signals($this->previousAsyncSignals);
        }
    }

    public function beginTurn(): void
    {
        $this->turnActive = true;
        $this->abortRequested = false;
    }

    public function endTurn(): void
    {
        $this->turnActive = false;
        $this->abortRequested = false;
    }

    private function handle(): void
    {
        if ($this->turnActive && ! $this->abortRequested) {
            $this->abortRequested = true;
            $this->outputGuard->writeError('Interrupt received. Aborting current turn. Press Ctrl+C again to exit.');
            $this->runtime->abort();

            return;
        }

        $this->outputGuard->writeError('Interrupted.');
        exit(130);
    }

    private function isSupported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows'
            && defined('SIGINT')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler')
            && function_exists('pcntl_async_signals');
    }
}
