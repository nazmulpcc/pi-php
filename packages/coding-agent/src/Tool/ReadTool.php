<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\AI\Schema\Type;

final class ReadTool extends AbstractTool
{
    public function __construct(
        private readonly string $cwd,
    ) {
        parent::__construct(
            name: 'read',
            description: 'Read a file from the working directory.',
            parameters: Type::object([
                'path' => Type::string(),
            ]),
        );
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        $path = PathHelper::resolve($this->cwd, (string) ($params['path'] ?? ''));
        if (! is_file($path)) {
            throw new \RuntimeException(sprintf('File not found: %s', $path));
        }

        return new AgentToolResult([
            new TextContent((string) file_get_contents($path)),
        ], details: ['path' => PathHelper::relative($this->cwd, $path)]);
    }
}
