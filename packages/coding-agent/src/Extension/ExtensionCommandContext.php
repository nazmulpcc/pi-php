<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final class ExtensionCommandContext extends ExtensionContext
{
    public function __construct(
        ExtensionUI $ui,
        bool $hasUi,
        string $cwd,
        mixed $sessionManager,
        mixed $modelRegistry,
        mixed $getModel,
        mixed $isIdle,
        mixed $abort,
        mixed $hasPendingMessages,
        mixed $shutdown,
        mixed $getContextUsage,
        mixed $compact,
        mixed $getSystemPrompt,
        mixed $assertActive,
        private readonly mixed $waitForIdle,
        private readonly mixed $newSession,
        private readonly mixed $fork,
        private readonly mixed $switchSession,
        private readonly mixed $reload,
    ) {
        parent::__construct(
            $ui,
            $hasUi,
            $cwd,
            $sessionManager,
            $modelRegistry,
            $getModel,
            $isIdle,
            $abort,
            $hasPendingMessages,
            $shutdown,
            $getContextUsage,
            $compact,
            $getSystemPrompt,
            $assertActive,
        );
    }

    public function waitForIdle(): mixed
    {
        return ($this->waitForIdle)();
    }

    public function newSession(array $options = []): mixed
    {
        return ($this->newSession)($options);
    }

    public function fork(string $entryId, array $options = []): mixed
    {
        return ($this->fork)($entryId, $options);
    }

    public function switchSession(string $sessionPath, array $options = []): mixed
    {
        return ($this->switchSession)($sessionPath, $options);
    }

    public function reload(): mixed
    {
        return ($this->reload)();
    }
}
