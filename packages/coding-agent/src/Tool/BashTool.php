<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Type;
use Pi\CodingAgent\Process\ProcessResult;
use Pi\CodingAgent\Process\ProcessRunner;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

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

    public function execute(
        string $toolCallId,
        array $params,
        ?CancellationToken $signal = null,
        ?callable $onUpdate = null,
    ): PromiseInterface {
        $command = (string) ($params['command'] ?? '');
        if ($command === '') {
            return reject(new \RuntimeException('Command must not be empty'));
        }

        $timeoutSeconds = max(1, (int) ($params['timeoutSeconds'] ?? 30));

        $runner = new ProcessRunner(
            command: $command,
            cwd: $this->cwd,
            timeoutSeconds: (float) $timeoutSeconds,
        );

        return $runner->run($signal, function (string $stdout, string $stderr) use ($onUpdate): void {
            if ($onUpdate === null) {
                return;
            }
            $onUpdate(new AgentToolResult(
                [new TextContent($this->formatOutput($stdout, $stderr, null))],
                ['stdout' => $stdout, 'stderr' => $stderr],
            ));
        })->then(function (ProcessResult $result) use ($command) {
            $outputText = $this->formatOutput($result->stdout, $result->stderr, $result->exitCode);

            return new AgentToolResult(
                content: [new TextContent($outputText)],
                details: [
                    'command' => $command,
                    'exitCode' => $result->exitCode,
                    'stdout' => $result->stdout,
                    'stderr' => $result->stderr,
                ],
                terminate: false,
            );
        });
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        throw new \LogicException('BashTool::doExecute() should never be called; execute() is overridden');
    }

    private function formatOutput(string $stdout, string $stderr, ?int $exitCode): string
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
}
