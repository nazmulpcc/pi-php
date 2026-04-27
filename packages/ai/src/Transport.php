<?php

declare(strict_types=1);

namespace Pi\AI;

enum Transport: string
{
    case Sse = 'sse';
    case Websocket = 'websocket';
    case Auto = 'auto';
}
