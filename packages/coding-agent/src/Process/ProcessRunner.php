<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Process;

use Pi\Agent\CancellationToken;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class ProcessRunner
{
    private const CANCEL_CHECK_INTERVAL = 0.05;

    public function __construct(
        private readonly string $command,
        private readonly ?string $cwd = null,
        private readonly ?array $env = null,
        private readonly ?float $timeoutSeconds = null,
    ) {}

    public function run(
        ?CancellationToken $signal = null,
        ?callable $onUpdate = null,
    ): PromiseInterface {
        if ($signal !== null && $signal->isCancelled()) {
            return \React\Promise\reject(new \RuntimeException('Tool execution aborted'));
        }

        $deferred = new Deferred;
        $process = new Process($this->command, $this->cwd, $this->env);

        $stdout = '';
        $stderr = '';
        $stdoutClosed = false;
        $stderrClosed = false;
        $exitCode = null;
        $exitSignal = null;
        $exited = false;
        $settled = false;
        $terminationReason = null;
        $timeoutTimer = null;
        $cancelTimer = null;
        $killTimer = null;

        $cleanup = function () use (&$timeoutTimer, &$cancelTimer, &$killTimer): void {
            if ($timeoutTimer !== null) {
                Loop::cancelTimer($timeoutTimer);
                $timeoutTimer = null;
            }
            if ($cancelTimer !== null) {
                Loop::cancelTimer($cancelTimer);
                $cancelTimer = null;
            }
            if ($killTimer !== null) {
                Loop::cancelTimer($killTimer);
                $killTimer = null;
            }
        };

        $trySettle = function () use (
            &$stdoutClosed, &$stderrClosed, &$exitCode, &$exitSignal, &$settled,
            &$terminationReason, &$stdout, &$stderr, &$exited,
            $deferred, $cleanup
        ): void {
            if ($settled) {
                return;
            }
            if (! $stdoutClosed || ! $stderrClosed || ! $exited) {
                return;
            }
            $settled = true;
            $cleanup();

            if ($terminationReason === 'timeout') {
                $output = self::formatOutput($stdout, $stderr, $exitCode);
                $deferred->reject(new \RuntimeException(
                    sprintf("Process timed out after %.1f seconds\n\n%s", $this->timeoutSeconds ?? 0, $output)
                ));

                return;
            }

            if ($terminationReason === 'cancelled') {
                $output = self::formatOutput($stdout, $stderr, $exitCode);
                $deferred->reject(new \RuntimeException("Tool execution aborted\n\n".$output));

                return;
            }

            $deferred->resolve(new ProcessResult($stdout, $stderr, $exitCode, $exitSignal));
        };

        $process->on('exit', function (?int $code, ?int $signal) use (&$exitCode, &$exitSignal, &$exited, $trySettle) {
            $exitCode = $code;
            $exitSignal = $signal;
            $exited = true;
            $trySettle();
        });

        try {
            $process->start();
        } catch (\Throwable $e) {
            $cleanup();

            return \React\Promise\reject(new \RuntimeException(
                'Unable to start process: '.$e->getMessage(),
                0,
                $e
            ));
        }

        $process->stdout->on('data', function (string $chunk) use (&$stdout, &$stderr, $onUpdate) {
            $stdout .= $chunk;
            if ($onUpdate !== null) {
                $onUpdate($stdout, $stderr);
            }
        });

        $process->stdout->on('close', function () use (&$stdoutClosed, $trySettle) {
            $stdoutClosed = true;
            $trySettle();
        });

        $process->stderr->on('data', function (string $chunk) use (&$stdout, &$stderr, $onUpdate) {
            $stderr .= $chunk;
            if ($onUpdate !== null) {
                $onUpdate($stdout, $stderr);
            }
        });

        $process->stderr->on('close', function () use (&$stderrClosed, $trySettle) {
            $stderrClosed = true;
            $trySettle();
        });

        if ($this->timeoutSeconds !== null && $this->timeoutSeconds > 0) {
            $timeoutTimer = Loop::addTimer($this->timeoutSeconds, function () use ($process, &$terminationReason, &$cancelTimer, &$killTimer) {
                $terminationReason = 'timeout';
                if ($cancelTimer !== null) {
                    Loop::cancelTimer($cancelTimer);
                    $cancelTimer = null;
                }
                self::terminateProcess($process);
                $killTimer = Loop::addTimer(0.5, function () use ($process) {
                    if ($process->isRunning()) {
                        self::terminateProcess($process, force: true);
                    }
                });
            });
        }

        if ($signal !== null) {
            $cancelTimer = Loop::addPeriodicTimer(self::CANCEL_CHECK_INTERVAL, function () use ($signal, $process, &$terminationReason, &$timeoutTimer, &$killTimer) {
                if ($signal->isCancelled()) {
                    $terminationReason = 'cancelled';
                    if ($timeoutTimer !== null) {
                        Loop::cancelTimer($timeoutTimer);
                        $timeoutTimer = null;
                    }
                    self::terminateProcess($process);
                    $killTimer = Loop::addTimer(0.5, function () use ($process) {
                        if ($process->isRunning()) {
                            self::terminateProcess($process, force: true);
                        }
                    });
                }
            });
        }

        return $deferred->promise();
    }

    private static function formatOutput(string $stdout, string $stderr, ?int $exitCode): string
    {
        $parts = [];
        if ($stdout !== '') {
            $parts[] = "STDOUT:\n".$stdout;
        }
        if ($stderr !== '') {
            $parts[] = "STDERR:\n".$stderr;
        }
        if ($parts === []) {
            $parts[] = '(no output)';
        }
        $text = implode("\n\n", $parts);
        if ($exitCode !== null && $exitCode !== 0) {
            $text .= "\n\nCommand exited with code ".$exitCode;
        }

        return $text;
    }

    private static function terminateProcess(Process $process, bool $force = false): void
    {
        if ($force && \PHP_OS_FAMILY !== 'Windows' && defined('SIGKILL')) {
            $process->terminate(SIGKILL);

            return;
        }

        if (! $force && \PHP_OS_FAMILY !== 'Windows' && defined('SIGTERM')) {
            $process->terminate(SIGTERM);

            return;
        }

        $process->terminate();
    }
}
