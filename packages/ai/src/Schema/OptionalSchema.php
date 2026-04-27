<?php

declare(strict_types=1);

namespace Pi\AI\Schema;

final class OptionalSchema extends Schema
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(array $definition)
    {
        parent::__construct($definition, true);
    }
}
