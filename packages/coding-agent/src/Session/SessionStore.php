<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

use Pi\Agent\ThinkingLevel;
use Pi\AI\Model;

interface SessionStore
{
    public function createSnapshot(
        string $cwd,
        ?Model $model,
        string $systemPrompt,
        ThinkingLevel $thinkingLevel,
        array $messages,
        ?string $sessionId = null,
    ): SessionSnapshot;

    public function save(SessionSnapshot $snapshot): SessionSnapshot;

    public function load(string $sessionIdOrPath): ?SessionSnapshot;

    public function loadLatest(): ?SessionSnapshot;
}
