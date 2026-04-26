<?php

declare(strict_types=1);

namespace Pi\Agent;

enum ToolExecutionMode: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
}
