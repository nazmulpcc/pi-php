<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Resource;

use Pi\CodingAgent\Diagnostics\Diagnostic;

interface ResourceLoaderInterface
{
    /**
     * @return array<ContextFile>
     */
    public function loadContextFiles(string $cwd): array;

    /**
     * @return array<Skill>
     */
    public function loadSkills(string $cwd): array;

    /**
     * @return array<PromptTemplate>
     */
    public function loadPromptTemplates(string $cwd): array;

    public function getSystemPrompt(): ?string;

    /**
     * @return list<string>
     */
    public function getAppendSystemPrompt(): array;

    /**
     * @return list<Diagnostic>
     */
    public function getDiagnostics(): array;

    /**
     * @param  list<string>  $skillPaths
     * @param  list<string>  $promptPaths
     * @param  list<string>  $themePaths
     */
    public function extendResources(array $skillPaths = [], array $promptPaths = [], array $themePaths = []): void;

    public function reload(): void;
}
