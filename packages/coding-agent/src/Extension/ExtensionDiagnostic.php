<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final readonly class ExtensionDiagnostic
{
    public function __construct(
        public string $path,
        public string $message,
        public string $severity = 'error',
    ) {}
}
