<?php

declare(strict_types=1);

namespace Pi\AI\Schema;

use JsonSerializable;

class Schema implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        protected array $definition,
        protected bool $optional = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
