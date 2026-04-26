<?php

declare(strict_types=1);

namespace Pi\Agent;

enum ThinkingLevel: string
{
    case Off = 'off';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';
}
