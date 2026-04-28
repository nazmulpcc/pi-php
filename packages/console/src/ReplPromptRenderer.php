<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\CodingAgent\CodingAgentState;

final class ReplPromptRenderer
{
    public function render(CodingAgentState $state): string
    {
        $model = $state->model?->id ?? 'unset';

        return sprintf('pi:%s %s > ', $model, $state->thinkingLevel->value);
    }
}
