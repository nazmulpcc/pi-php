<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\AI\Schema\Type;

final class FindTool extends AbstractTool
{
    public function __construct(
        private readonly string $cwd,
    ) {
        parent::__construct(
            name: 'find',
            description: 'Find files by name fragment.',
            parameters: Type::object([
                'path' => Type::string(),
                'pattern' => Type::string(),
            ]),
        );
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        $path = PathHelper::resolve($this->cwd, (string) ($params['path'] ?? '.'));
        $pattern = strtolower((string) ($params['pattern'] ?? ''));
        if (! is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory not found: %s', $path));
        }

        $matches = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }
            if ($pattern === '' || str_contains(strtolower($file->getFilename()), $pattern)) {
                $matches[] = PathHelper::relative($this->cwd, $file->getPathname());
            }
        }

        sort($matches);

        return new AgentToolResult([
            new TextContent(implode("\n", $matches)),
        ], details: ['path' => PathHelper::relative($this->cwd, $path), 'count' => count($matches)]);
    }
}
