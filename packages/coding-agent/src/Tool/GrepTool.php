<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

use Pi\Agent\CancellationToken;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\AI\Schema\Type;

final class GrepTool extends AbstractTool
{
    public function __construct(
        private readonly string $cwd,
    ) {
        parent::__construct(
            name: 'grep',
            description: 'Search file contents for a text pattern.',
            parameters: Type::object([
                'path' => Type::string(),
                'pattern' => Type::string(),
            ]),
        );
    }

    protected function doExecute(string $toolCallId, array $params, ?CancellationToken $signal = null, ?callable $onUpdate = null): AgentToolResult
    {
        $path = PathHelper::resolve($this->cwd, (string) ($params['path'] ?? '.'));
        $pattern = (string) ($params['pattern'] ?? '');
        if ($pattern === '') {
            throw new \RuntimeException('Pattern must not be empty');
        }

        $lines = [];
        $iterator = is_dir($path)
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
            : new \ArrayIterator([new \SplFileInfo($path)]);

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $content = @file_get_contents($file->getPathname());
            if (! is_string($content) || ! str_contains($content, $pattern)) {
                continue;
            }

            foreach (explode("\n", $content) as $index => $line) {
                if (str_contains($line, $pattern)) {
                    $lines[] = sprintf('%s:%d:%s', PathHelper::relative($this->cwd, $file->getPathname()), $index + 1, $line);
                }
            }
        }

        return new AgentToolResult([
            new TextContent(implode("\n", $lines)),
        ], details: ['path' => PathHelper::relative($this->cwd, $path), 'count' => count($lines)]);
    }
}
