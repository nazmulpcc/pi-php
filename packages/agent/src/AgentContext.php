<?php

declare(strict_types=1);

namespace Pi\Agent;

class AgentContext
{
    public function __construct(
        public string $systemPrompt,
        public array $messages,
        public array $tools,
    ) {}
}
