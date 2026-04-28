<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final readonly class ExtensionPackageResolution
{
    /**
     * @param  list<string>  $extensionPaths
     * @param  list<string>  $skillPaths
     * @param  list<string>  $promptPaths
     * @param  list<string>  $themePaths
     */
    public function __construct(
        public array $extensionPaths = [],
        public array $skillPaths = [],
        public array $promptPaths = [],
        public array $themePaths = [],
    ) {}
}
