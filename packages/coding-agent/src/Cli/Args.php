<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Cli;

use Pi\Agent\ThinkingLevel;

readonly class Args
{
    /**
     * @param  array<string>  $messages
     * @param  array<string>|null  $tools
     */
    public function __construct(
        public string $mode = 'text',
        public ?string $provider = null,
        public ?string $modelId = null,
        public ?string $apiKey = null,
        public ?string $systemPrompt = null,
        public ?ThinkingLevel $thinkingLevel = null,
        public bool $continueLatest = false,
        public ?string $resume = null,
        public bool $noSession = false,
        public ?string $sessionDir = null,
        public ?array $tools = null,
        public array $messages = [],
        public ?string $cwd = null,
    ) {}
}
