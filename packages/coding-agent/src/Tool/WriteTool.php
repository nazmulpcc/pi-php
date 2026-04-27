<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Type;

final class WriteTool extends AbstractTool
{
    public function __construct(
        private readonly string $cwd,
    ) {
        parent::__construct(
            name: 'write',
            description: 'Write content to a file.',
            parameters: Type::object([
                'path' => Type::string(),
                'content' => Type::string(),
                'append' => Type::boolean(),
            ]),
            executionMode: ToolExecutionMode::Sequential,
        );
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        $path = PathHelper::resolve($this->cwd, (string) ($params['path'] ?? ''));
        $content = (string) ($params['content'] ?? '');
        $append = (bool) ($params['append'] ?? false);

        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($concurrentDirectory = $dir, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $dir));
        }

        file_put_contents($path, $content, $append ? FILE_APPEND : 0);

        return new AgentToolResult([
            new TextContent(sprintf('%s %s', $append ? 'Appended to' : 'Wrote', PathHelper::relative($this->cwd, $path))),
        ], details: ['path' => PathHelper::relative($this->cwd, $path), 'bytes' => strlen($content), 'append' => $append]);
    }
}
