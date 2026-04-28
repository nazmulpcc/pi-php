<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

class ExtensionContext
{
    /**
     * @param  callable(): mixed  $assertActive
     */
    public function __construct(
        public readonly ExtensionUI $ui,
        public readonly bool $hasUi,
        public readonly string $cwd,
        public readonly mixed $sessionManager,
        public readonly mixed $modelRegistry,
        private readonly mixed $getModel,
        private readonly mixed $isIdle,
        private readonly mixed $abort,
        private readonly mixed $hasPendingMessages,
        private readonly mixed $shutdown,
        private readonly mixed $getContextUsage,
        private readonly mixed $compact,
        private readonly mixed $getSystemPrompt,
        private readonly mixed $assertActive,
    ) {}

    public function model(): mixed
    {
        ($this->assertActive)();

        return ($this->getModel)();
    }

    public function isIdle(): bool
    {
        ($this->assertActive)();

        return (bool) ($this->isIdle)();
    }

    public function abort(): void
    {
        ($this->assertActive)();
        ($this->abort)();
    }

    public function hasPendingMessages(): bool
    {
        ($this->assertActive)();

        return (bool) ($this->hasPendingMessages)();
    }

    public function shutdown(): void
    {
        ($this->assertActive)();
        ($this->shutdown)();
    }

    public function getContextUsage(): mixed
    {
        ($this->assertActive)();

        return ($this->getContextUsage)();
    }

    public function compact(array $options = []): mixed
    {
        ($this->assertActive)();

        return ($this->compact)($options);
    }

    public function getSystemPrompt(): string
    {
        ($this->assertActive)();

        return (string) ($this->getSystemPrompt)();
    }
}
