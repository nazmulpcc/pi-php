<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

use Pi\CodingAgent\Diagnostics\Diagnostic;

final readonly class ExtensionPackageDiagnostic extends Diagnostic
{
    public function __construct(
        string $message,
        string $severity = 'error',
        ?string $scope = null,
        ?string $path = null,
    ) {
        parent::__construct('extension-package', $message, $severity, $scope, $path);
    }
}
