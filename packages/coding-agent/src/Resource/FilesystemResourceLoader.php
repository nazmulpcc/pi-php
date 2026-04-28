<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Resource;

use Pi\CodingAgent\Diagnostics\Diagnostic;
use Pi\CodingAgent\Settings\SettingsManager;

final class FilesystemResourceLoader implements ResourceLoaderInterface
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @var list<string> */
    private array $extensionSkillPaths = [];

    /** @var list<string> */
    private array $extensionPromptPaths = [];

    /** @var list<string> */
    private array $extensionThemePaths = [];

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
                    if (! is_readable($path)) {
                        $this->diagnostics[] = new Diagnostic('resources', sprintf('Unable to read %s', $path), 'error', 'context', $path);

                        continue;
                    }

                    $content = file_get_contents($path);
                    if (is_string($content)) {
                        $results[] = new ContextFile($path, $content);
                    } else {
                        $this->diagnostics[] = new Diagnostic('resources', sprintf('Unable to read %s', $path), 'error', 'context', $path);
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
        $paths = array_merge($paths, $this->extensionSkillPaths);

        return $this->loadNamedMarkdownResources($paths, Skill::class);
    }

    public function loadPromptTemplates(string $cwd): array
    {
        $paths = $this->defaultPromptPaths($cwd);
        if ($this->settingsManager !== null) {
            $paths = array_merge($paths, $this->settingsManager->getPromptPaths());
        }
        $paths = array_merge($paths, $this->extensionPromptPaths);

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

    public function extendResources(array $skillPaths = [], array $promptPaths = [], array $themePaths = []): void
    {
        $this->extensionSkillPaths = array_values(array_unique([...$this->extensionSkillPaths, ...$skillPaths]));
        $this->extensionPromptPaths = array_values(array_unique([...$this->extensionPromptPaths, ...$promptPaths]));
        $this->extensionThemePaths = array_values(array_unique([...$this->extensionThemePaths, ...$themePaths]));
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

                if (! is_readable($path)) {
                    $this->diagnostics[] = new Diagnostic('resources', sprintf('Unable to read %s', $path), 'error', 'resource', $path);

                    continue;
                }

                $content = file_get_contents($path);
                if (! is_string($content)) {
                    $this->diagnostics[] = new Diagnostic('resources', sprintf('Unable to read %s', $path), 'error', 'resource', $path);

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
