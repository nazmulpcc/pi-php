<?php

declare(strict_types=1);

namespace Pi\AI;

enum CacheRetention: string
{
    case None = 'none';
    case Short = 'short';
    case Long = 'long';
}
