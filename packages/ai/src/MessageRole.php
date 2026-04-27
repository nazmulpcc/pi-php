<?php

declare(strict_types=1);

namespace Pi\AI;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case ToolResult = 'toolResult';
}
