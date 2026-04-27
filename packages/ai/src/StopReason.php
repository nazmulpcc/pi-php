<?php

declare(strict_types=1);

namespace Pi\AI;

enum StopReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolUse = 'toolUse';
    case Error = 'error';
    case Aborted = 'aborted';
}
