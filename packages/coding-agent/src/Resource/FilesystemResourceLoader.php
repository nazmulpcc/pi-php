<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Resource;

use Pi\CodingAgent\Settings\SettingsManager;

final class FilesystemResourceLoader implements ResourceLoaderInterface
{
    /** @var list<array{scope:string,error:string}> */
    private array $diagnostics = [];

    /**
     * @param  array<string>  $contextFileNames
     * @param  array<string>  $appendSystemPrompt
     */
    public function __construct(
        private readonly ?string $cwd = null,
        private readonly ?SettingsManager $settingsManager = null,
        private readonly ?string $systemPrompt = null,
        private readonly array $appendSystemPrompt = [],
        private readonly bool $enableContextFiles = true,
        private array $contextFileNames = ['AGENTS.md', 'CLAUDE.md'],
    ) {}

    public function loadContextFiles(string $cwd): array
    {
        if (! $this->enableContextFiles) {
            return [];
        }

        $results = [];
        $seen = [];
        $dir = realpath($cwd) ?: $cwd;

        while ($dir !== '') {
            foreach ($this->contextFileNames as $name) {
                $path = $dir.DIRECTORY_SEPARATOR.$name;
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;

                if (is_file($path)) {
                    $content = @file_get_contents($path);
                    if (is_string($content)) {
                        $results[] = new ContextFile($path, $content);
                    } else {
                        $this->diagnostics[] = ['scope' => 'context', 'error' => sprintf('Unable to read %s', $path)];
                    }
                }
            }

            if ($dir === DIRECTORY_SEPARATOR) {
                break;
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
        $paths = $this->defaultSkillPaths($cwd);
        if ($this->settingsManager !== null) {
            $paths = array_merge($paths, $this->settingsManager->getSkillPaths());
        }

        return $this->loadNamedMarkdownResources($paths, Skill::class);
    }

    public function loadPromptTemplates(string $cwd): array
    {
        $paths = $this->defaultPromptPaths($cwd);
        if ($this->settingsManager !== null) {
            $paths = array_merge($paths, $this->settingsManager->getPromptPaths());
        }

        /** @var array<PromptTemplate> $templates */
        $templates = [];
        foreach ($this->loadNamedMarkdownResources($paths, PromptTemplate::class) as $resource) {
            $templates[] = $resource;
        }

        return $templates;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function getAppendSystemPrompt(): array
    {
        return $this->appendSystemPrompt;
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    public function reload(): void
    {
        $this->diagnostics = [];
        $this->settingsManager?->reload();
    }

    /**
     * @param  list<string>  $paths
     * @return array<Skill|PromptTemplate>
     */
    private function loadNamedMarkdownResources(array $paths, string $class): array
    {
        $resources = [];
        $seen = [];

        foreach ($paths as $base) {
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'md') {
                    continue;
                }

                $path = $file->getPathname();
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;

                $content = @file_get_contents($path);
                if (! is_string($content)) {
                    $this->diagnostics[] = ['scope' => 'resource', 'error' => sprintf('Unable to read %s', $path)];

                    continue;
                }

                $name = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                $resources[] = $class === PromptTemplate::class
                    ? new PromptTemplate($name, $path, $content)
                    : new Skill($name, $path, $content);
            }
        }

        usort($resources, static fn (object $a, object $b): int => strcmp($a->path, $b->path));

        return $resources;
    }

    /**
     * @return list<string>
     */
    private function defaultSkillPaths(string $cwd): array
    {
        return [
            rtrim($cwd, '/').DIRECTORY_SEPARATOR.'.pi'.DIRECTORY_SEPARATOR.'skills',
            rtrim($cwd, '/').DIRECTORY_SEPARATOR.'skills',
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultPromptPaths(string $cwd): array
    {
        return [
            rtrim($cwd, '/').DIRECTORY_SEPARATOR.'.pi'.DIRECTORY_SEPARATOR.'prompts',
            rtrim($cwd, '/').DIRECTORY_SEPARATOR.'prompts',
            rtrim($cwd, '/').DIRECTORY_SEPARATOR.'.pi'.DIRECTORY_SEPARATOR.'prompt-templates',
            rtrim($cwd, '/').DIRECTORY_SEPARATOR.'prompt-templates',
        ];
    }
}
