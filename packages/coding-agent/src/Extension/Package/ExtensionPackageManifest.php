<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final readonly class ExtensionPackageManifest
{
    /**
     * @param  list<string>  $extensions
     * @param  list<string>  $skills
     * @param  list<string>  $prompts
     * @param  list<string>  $themes
     */
    public function __construct(
        public string $name,
        public array $extensions = [],
        public array $skills = [],
        public array $prompts = [],
        public array $themes = [],
    ) {}
}
