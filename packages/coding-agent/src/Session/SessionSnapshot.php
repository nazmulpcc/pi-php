<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

use Pi\Agent\AgentMessage;
use Pi\Agent\ThinkingLevel;
use Pi\AI\Model;

readonly class SessionSnapshot
{
    /**
     * @param  array<AgentMessage>  $messages
     */
    public function __construct(
        public string $sessionId,
        public string $cwd,
        public ?Model $model,
        public string $systemPrompt,
        public ThinkingLevel $thinkingLevel,
        public array $messages,
        public int $createdAt,
        public int $updatedAt,
        public ?string $path = null,
    ) {}
}
