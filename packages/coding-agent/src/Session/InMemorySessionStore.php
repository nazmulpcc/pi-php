<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

use Pi\Agent\ThinkingLevel;
use Pi\AI\Model;

final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, SessionSnapshot> */
    private array $snapshots = [];

    public function createSnapshot(
        string $cwd,
        ?Model $model,
        string $systemPrompt,
        ThinkingLevel $thinkingLevel,
        array $messages,
        ?string $sessionId = null,
    ): SessionSnapshot {
        $now = (int) (microtime(true) * 1000);

        return new SessionSnapshot(
            sessionId: $sessionId ?? bin2hex(random_bytes(16)),
            cwd: $cwd,
            model: $model,
            systemPrompt: $systemPrompt,
            thinkingLevel: $thinkingLevel,
            messages: $messages,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function save(SessionSnapshot $snapshot): SessionSnapshot
    {
        $updated = new SessionSnapshot(
            sessionId: $snapshot->sessionId,
            cwd: $snapshot->cwd,
            model: $snapshot->model,
            systemPrompt: $snapshot->systemPrompt,
            thinkingLevel: $snapshot->thinkingLevel,
            messages: $snapshot->messages,
            createdAt: $snapshot->createdAt,
            updatedAt: (int) (microtime(true) * 1000),
            path: $snapshot->path,
        );
        $this->snapshots[$updated->sessionId] = $updated;

        return $updated;
    }

    public function load(string $sessionIdOrPath): ?SessionSnapshot
    {
        if (isset($this->snapshots[$sessionIdOrPath])) {
            return $this->snapshots[$sessionIdOrPath];
        }

        foreach ($this->snapshots as $snapshot) {
            if (str_starts_with($snapshot->sessionId, $sessionIdOrPath)) {
                return $snapshot;
            }
        }

        return null;
    }

    public function loadLatest(): ?SessionSnapshot
    {
        $snapshots = array_values($this->snapshots);
        usort($snapshots, static fn (SessionSnapshot $a, SessionSnapshot $b): int => $b->updatedAt <=> $a->updatedAt);

        return $snapshots[0] ?? null;
    }
}
