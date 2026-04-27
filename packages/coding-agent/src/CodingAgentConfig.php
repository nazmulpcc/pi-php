<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\Agent\ThinkingLevel;
use Pi\Agent\Tool\AgentTool;
use Pi\AI\Model;
use Pi\CodingAgent\Resource\ResourceLoaderInterface;
use Pi\CodingAgent\Session\SessionStore;

readonly class CodingAgentConfig
{
    /**
     * @param  array<AgentTool>  $tools
     * @param  array<string>|null  $allowedToolNames
     */
    public function __construct(
        public ?Model $model = null,
        public ?string $provider = null,
        public ?string $modelId = null,
        public ?string $apiKey = null,
        public ?string $cwd = null,
        public ?string $systemPrompt = null,
        public ThinkingLevel $thinkingLevel = ThinkingLevel::Medium,
        public array $tools = [],
        public ?array $allowedToolNames = null,
        public ?SessionStore $sessionStore = null,
        public ?ResourceLoaderInterface $resourceLoader = null,
        public mixed $streamFn = null,
        public mixed $getApiKey = null,
        public bool $enableContextFiles = true,
        public ?string $sessionId = null,
    ) {}
}
