<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final class Extension
{
    /** @var array<string, list<callable(array, ExtensionContext): mixed>> */
    public array $handlers = [];

    /** @var array<string, ExtensionTool> */
    public array $tools = [];

    /** @var array<string, ExtensionCommand> */
    public array $commands = [];

    /** @var array<string, ExtensionFlag> */
    public array $flags = [];

    /** @var array<string, ExtensionProvider> */
    public array $providers = [];

    public ?ExtensionAPI $api = null;

    public function __construct(
        public readonly string $path,
        public readonly string $resolvedPath,
    ) {}
}
