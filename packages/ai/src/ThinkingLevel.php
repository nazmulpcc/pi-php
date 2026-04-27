<?php

declare(strict_types=1);

namespace Pi\AI;

enum ThinkingLevel: string
{
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';
}
