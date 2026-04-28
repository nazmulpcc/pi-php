<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\CodingAgent\Diagnostics\Diagnostic;

final readonly class ExtensionDiagnostic extends Diagnostic
{
    public function __construct(
        string $path,
        string $message,
        string $severity = 'error',
    ) {
        parent::__construct('extension', $message, $severity, 'extension', $path);
    }
}
