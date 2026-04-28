<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\AI\ApiProviderInterface;
use Pi\AI\Model;

final readonly class ExtensionProvider
{
    /**
     * @param  array<Model>  $models
     */
    public function __construct(
        public string $name,
        public ?ApiProviderInterface $provider = null,
        public ?\Closure $factory = null,
        public array $models = [],
    ) {}
}
