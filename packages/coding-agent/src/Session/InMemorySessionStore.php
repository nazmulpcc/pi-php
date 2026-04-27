<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, SessionManager> */
    private array $sessions = [];

    public function createManager(string $cwd, ?string $sessionId = null): SessionManager
    {
        $manager = SessionManager::create($cwd, null, false, $sessionId);
        $this->sessions[$manager->getSessionId()] = $manager;

        return $manager;
    }

    public function openManager(string $sessionIdOrPath, ?string $cwd = null): ?SessionManager
    {
        if (isset($this->sessions[$sessionIdOrPath])) {
            return $this->sessions[$sessionIdOrPath];
        }

        foreach ($this->sessions as $sessionId => $manager) {
            if (str_starts_with($sessionId, $sessionIdOrPath)) {
                return $manager;
            }
        }

        return null;
    }

    public function continueLatest(string $cwd): ?SessionManager
    {
        $sessions = array_filter(
            $this->sessions,
            static fn (SessionManager $manager): bool => $manager->getCwd() === $cwd,
        );

        if ($sessions === []) {
            return null;
        }

        usort($sessions, static fn (SessionManager $a, SessionManager $b): int => strcmp($b->getLastTimestamp(), $a->getLastTimestamp()));

        return $sessions[0] ?? null;
    }
}
