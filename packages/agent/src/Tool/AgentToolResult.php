<?php

declare(strict_types=1);

namespace Pi\Agent\Tool;

use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;

readonly class AgentToolResult
{
    /**
     * @param  array<TextContent|ImageContent>  $content
     */
    public function __construct(
        public array $content,
        public mixed $details = null,
        public bool $terminate = false,
    ) {}
}
