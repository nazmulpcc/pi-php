<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\CodingAgent\Resource\ContextFile;

final class SystemPromptBuilder
{
    /**
     * @param  array<ContextFile>  $contextFiles
     */
    public static function build(string $basePrompt, array $contextFiles = []): string
    {
        $parts = [trim($basePrompt)];

        foreach ($contextFiles as $contextFile) {
            $parts[] = sprintf(
                "Context file: %s\n\n%s",
                $contextFile->path,
                trim($contextFile->content),
            );
        }

        return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')));
    }
}
