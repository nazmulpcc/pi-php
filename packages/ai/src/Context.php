<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class Context
{
    /**
     * @param  array<Message\Message>  $messages
     * @param  array<Tool>  $tools
     */
    public function __construct(
        public array $messages,
        public ?string $systemPrompt = null,
        public array $tools = [],
    ) {}
}
