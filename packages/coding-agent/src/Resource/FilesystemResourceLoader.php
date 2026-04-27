<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Resource;

final readonly class FilesystemResourceLoader implements ResourceLoaderInterface
{
    /**
     * @param  array<string>  $contextFileNames
     */
    public function __construct(
        private array $contextFileNames = ['AGENTS.md', 'CLAUDE.md'],
    ) {}

    public function loadContextFiles(string $cwd): array
    {
        $results = [];
        $dir = realpath($cwd) ?: $cwd;

        while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR) {
            foreach ($this->contextFileNames as $name) {
                $path = $dir.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    $results[] = new ContextFile($path, (string) file_get_contents($path));
                }
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return array_reverse($results);
    }

    public function loadSkills(string $cwd): array
    {
        return $this->loadNamedMarkdownResources($cwd, ['skills']);
    }

    public function loadPromptTemplates(string $cwd): array
    {
        $templates = [];
        foreach ($this->loadNamedMarkdownResources($cwd, ['prompts', 'prompt-templates']) as $resource) {
            $templates[] = new PromptTemplate($resource->name, $resource->path, $resource->content);
        }

        return $templates;
    }

    /**
     * @param  array<string>  $dirNames
     * @return array<Skill>
     */
    private function loadNamedMarkdownResources(string $cwd, array $dirNames): array
    {
        $resources = [];
        $paths = [];

        foreach ($dirNames as $dirName) {
            $paths[] = rtrim($cwd, '/').DIRECTORY_SEPARATOR.'.pi'.DIRECTORY_SEPARATOR.$dirName;
            $paths[] = rtrim($cwd, '/').DIRECTORY_SEPARATOR.$dirName;
        }

        foreach ($paths as $base) {
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'md') {
                    continue;
                }

                $resources[] = new Skill(
                    name: pathinfo($file->getFilename(), PATHINFO_FILENAME),
                    path: $file->getPathname(),
                    content: (string) file_get_contents($file->getPathname()),
                );
            }
        }

        usort($resources, static fn (Skill $a, Skill $b): int => strcmp($a->path, $b->path));

        return $resources;
    }
}
