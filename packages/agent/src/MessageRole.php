<?php

declare(strict_types=1);

namespace Pi\Agent;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case ToolResult = 'toolResult';
    case Custom = 'custom';
}
