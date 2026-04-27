<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Resource;

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
}
