<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

interface SessionStore
{
    public function createManager(string $cwd, ?string $sessionId = null, ?string $parentSession = null): SessionManager;

    public function openManager(string $sessionIdOrPath, ?string $cwd = null): ?SessionManager;

    public function continueLatest(string $cwd): ?SessionManager;
}
