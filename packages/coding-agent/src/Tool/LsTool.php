<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\AI\Schema\Type;

final class LsTool extends AbstractTool
{
    public function __construct(
        private readonly string $cwd,
    ) {
        parent::__construct(
            name: 'ls',
            description: 'List files in a directory.',
            parameters: Type::object([
                'path' => Type::string(),
            ]),
        );
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        $path = PathHelper::resolve($this->cwd, (string) ($params['path'] ?? '.'));
        if (! is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory not found: %s', $path));
        }

        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        sort($entries);

        return new AgentToolResult([
            new TextContent(implode("\n", $entries)),
        ], details: ['path' => PathHelper::relative($this->cwd, $path), 'count' => count($entries)]);
    }
}
