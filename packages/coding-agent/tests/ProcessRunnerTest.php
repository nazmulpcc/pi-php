<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\SimpleCancellationToken;
use Pi\CodingAgent\Process\ProcessRunner;
use React\EventLoop\Loop;

describe('ProcessRunner', function () {
    it('runs a command and captures stdout', function () {
        $runner = new ProcessRunner(PHP_OS_FAMILY === 'Windows' ? 'php -r "echo 123;"' : 'printf 123');
        $result = codingAgentBlock($runner->run());

        expect($result->stdout)->toBe('123');
        expect($result->stderr)->toBe('');
        expect($result->exitCode)->toBe(0);
    });

    it('captures stderr separately', function () {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'php -r "fwrite(STDERR, \"error\");"'
            : 'printf error >&2';
        $runner = new ProcessRunner($cmd);
        $result = codingAgentBlock($runner->run());

        expect($result->stdout)->toBe('');
        expect($result->stderr)->toBe('error');
        expect($result->exitCode)->toBe(0);
    });

    it('streams output via onUpdate', function () {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'php -r "echo \"line1\n\"; echo \"line2\n\";"'
            : 'printf "line1\nline2\n"';
        $runner = new ProcessRunner($cmd);
        $updates = [];
        $result = codingAgentBlock($runner->run(onUpdate: function (string $stdout, string $stderr) use (&$updates): void {
            $updates[] = $stdout;
        }));

        expect($result->stdout)->toBe("line1\nline2\n");
        expect(count($updates))->toBeGreaterThanOrEqual(1);
        expect(end($updates))->toBe("line1\nline2\n");
    });

    it('times out and rejects', function () {
        $cmd = PHP_OS_FAMILY === 'Windows' ? 'php -r "sleep(2);"' : 'sleep 2';
        $runner = new ProcessRunner($cmd, timeoutSeconds: 0.1);

        expect(function () use ($runner) {
            codingAgentBlock($runner->run());
        })->toThrow(RuntimeException::class, 'timed out');
    });

    it('rejects when cancelled before start', function () {
        $token = new SimpleCancellationToken;
        $token->cancel();
        $runner = new ProcessRunner('echo hello');

        expect(function () use ($runner, $token) {
            codingAgentBlock($runner->run(signal: $token));
        })->toThrow(RuntimeException::class, 'aborted');
    });

    it('rejects when cancelled during execution', function () {
        $cmd = PHP_OS_FAMILY === 'Windows' ? 'php -r "sleep(2);"' : 'sleep 2';
        $runner = new ProcessRunner($cmd);
        $token = new SimpleCancellationToken;

        $promise = $runner->run(signal: $token);
        Loop::addTimer(0.1, function () use ($token): void {
            $token->cancel();
        });

        expect(function () use ($promise) {
            codingAgentBlock($promise);
        })->toThrow(RuntimeException::class, 'aborted');
    });

    it('runs in a specific working directory', function () {
        $dir = codingAgentTempDir();
        file_put_contents($dir.'/test.txt', 'hello');
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'php -r "echo file_get_contents(\"test.txt\");"'
            : 'cat test.txt';
        $runner = new ProcessRunner($cmd, cwd: $dir);
        $result = codingAgentBlock($runner->run());

        expect($result->stdout)->toBe('hello');
        codingAgentDeleteDir($dir);
    });

    it('returns non-zero exit code without throwing', function () {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'php -r "exit(1);"'
            : 'exit 1';
        $runner = new ProcessRunner($cmd);
        $result = codingAgentBlock($runner->run());

        expect($result->exitCode)->toBe(1);
    });
});
