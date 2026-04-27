<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;
use Pi\AI\Schema\Type;

final class EditTool extends AbstractTool
{
    public function __construct(
        private readonly string $cwd,
    ) {
        parent::__construct(
            name: 'edit',
            description: 'Replace text in a file.',
            parameters: Type::object([
                'path' => Type::string(),
                'search' => Type::string(),
                'replace' => Type::string(),
                'replaceAll' => Type::boolean(),
            ]),
            executionMode: ToolExecutionMode::Sequential,
        );
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        $path = PathHelper::resolve($this->cwd, (string) ($params['path'] ?? ''));
        if (! is_file($path)) {
            throw new \RuntimeException(sprintf('File not found: %s', $path));
        }

        $search = (string) ($params['search'] ?? '');
        $replace = (string) ($params['replace'] ?? '');
        $replaceAll = (bool) ($params['replaceAll'] ?? false);

        if ($search === '') {
            throw new \RuntimeException('Search text must not be empty');
        }

        $content = (string) file_get_contents($path);
        if (! str_contains($content, $search)) {
            throw new \RuntimeException('Search text not found in file');
        }

        $updated = $replaceAll ? str_replace($search, $replace, $content) : preg_replace('/'.preg_quote($search, '/').'/', $replace, $content, 1);
        file_put_contents($path, $updated);

        return new AgentToolResult([
            new TextContent(sprintf('Edited %s', PathHelper::relative($this->cwd, $path))),
        ], details: ['path' => PathHelper::relative($this->cwd, $path), 'replaceAll' => $replaceAll]);
    }
}
