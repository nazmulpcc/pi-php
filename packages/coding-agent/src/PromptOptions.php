<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\Agent\Content\ImageContent;

readonly class PromptOptions
{
    /**
     * @param  array<ImageContent>  $images
     */
    public function __construct(
        public array $images = [],
    ) {}
}
