<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final readonly class ExtensionLoadResult
{
    /**
     * @param  array<Extension>  $extensions
     * @param  array<ExtensionDiagnostic>  $diagnostics
     */
    public function __construct(
        public array $extensions,
        public array $diagnostics = [],
    ) {}
}
