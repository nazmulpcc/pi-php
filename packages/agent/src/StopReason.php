<?php

declare(strict_types=1);

namespace Pi\Agent;

enum StopReason: string
{
    case Done = 'done';
    case Error = 'error';
    case Aborted = 'aborted';
    case ToolCalls = 'toolCalls';
    case Length = 'length';
}
