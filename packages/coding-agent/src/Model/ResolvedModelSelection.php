<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Model;

use Pi\AI\Model;

readonly class ResolvedModelSelection
{
    public function __construct(
        public ?Model $model,
        public ?string $provider,
        public ?string $modelId,
        public ?string $source,
    ) {}
}
