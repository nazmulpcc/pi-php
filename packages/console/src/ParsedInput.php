<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\Agent\Content\ImageContent;
use Pi\Agent\ThinkingLevel;

readonly class ParsedInput
{
    /**
     * @param  list<string>  $messages
     * @param  list<string>  $fileArgs
     * @param  list<ImageContent>  $fileImages
     * @param  list<string>|null  $allowedToolNames
     * @param  list<string>  $appendSystemPrompt
     */
    public function __construct(
        public ?string $mode,
        public ?string $provider,
        public ?string $modelId,
        public ?string $apiKey,
        public ?string $systemPrompt,
        public array $appendSystemPrompt,
        public ?ThinkingLevel $thinkingLevel,
        public bool $continueLatest,
        public string|bool|null $resume,
        public ?string $sessionTarget,
        public bool $noSession,
        public ?string $sessionDir,
        public ?array $allowedToolNames,
        public bool $enableContextFiles,
        public ?string $cwd,
        public bool|string $listModels,
        public array $messages,
        public array $fileArgs,
        public string $fileText,
        public array $fileImages,
    ) {}
}
