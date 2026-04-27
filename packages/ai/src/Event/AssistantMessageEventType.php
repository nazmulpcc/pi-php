<?php

declare(strict_types=1);

namespace Pi\AI\Event;

enum AssistantMessageEventType: string
{
    case Start = 'start';
    case TextStart = 'text_start';
    case TextDelta = 'text_delta';
    case TextEnd = 'text_end';
    case ThinkingStart = 'thinking_start';
    case ThinkingDelta = 'thinking_delta';
    case ThinkingEnd = 'thinking_end';
    case ToolCallStart = 'toolcall_start';
    case ToolCallDelta = 'toolcall_delta';
    case ToolCallEnd = 'toolcall_end';
    case Done = 'done';
    case Error = 'error';
}
