<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Type;

final class BashTool extends AbstractTool
{
    public function __construct(
        private readonly string $cwd,
    ) {
        parent::__construct(
            name: 'bash',
            description: 'Run a shell command in the working directory.',
            parameters: Type::object([
                'command' => Type::string(),
                'timeoutSeconds' => Type::integer(),
            ]),
            executionMode: ToolExecutionMode::Sequential,
        );
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        $command = (string) ($params['command'] ?? '');
        if ($command === '') {
            throw new \RuntimeException('Command must not be empty');
        }

        $timeoutSeconds = max(1, (int) ($params['timeoutSeconds'] ?? 30));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->cwd);
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start bash command');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);

        while (true) {
            $status = proc_get_status($process);
            $stdoutChunk = stream_get_contents($pipes[1]);
            $stderrChunk = stream_get_contents($pipes[2]);

            if (is_string($stdoutChunk) && $stdoutChunk !== '') {
                $stdout .= $stdoutChunk;
                if ($onUpdate !== null) {
                    $onUpdate(new AgentToolResult([new TextContent($stdout)], ['stdout' => $stdout, 'stderr' => $stderr]));
                }
            }

            if (is_string($stderrChunk) && $stderrChunk !== '') {
                $stderr .= $stderrChunk;
            }

            if (! $status['running']) {
                break;
            }

            if ($signal?->isCancelled()) {
                proc_terminate($process);
                throw new \RuntimeException('Tool execution aborted');
            }

            if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
                proc_terminate($process);
                throw new \RuntimeException(sprintf('Command timed out after %d seconds', $timeoutSeconds));
            }

            usleep(50_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $outputParts = [];
        if ($stdout !== '') {
            $outputParts[] = "STDOUT:\n".$stdout;
        }
        if ($stderr !== '') {
            $outputParts[] = "STDERR:\n".$stderr;
        }
        if ($outputParts === []) {
            $outputParts[] = '(no output)';
        }

        return new AgentToolResult(
            content: [new TextContent(implode("\n\n", $outputParts))],
            details: ['command' => $command, 'exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr],
            terminate: false,
        );
    }
}
