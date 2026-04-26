<?php

declare(strict_types=1);

namespace Pi\Agent;

class PendingMessageQueue
{
    private array $messages = [];

    public function __construct(private string $mode = 'one-at-a-time') {}

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function enqueue(AgentMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function hasItems(): bool
    {
        return count($this->messages) > 0;
    }

    public function drain(): array
    {
        if ($this->mode === 'all') {
            $drained = $this->messages;
            $this->messages = [];

            return $drained;
        }

        $first = $this->messages[0] ?? null;
        if ($first === null) {
            return [];
        }
        $this->messages = array_slice($this->messages, 1);

        return [$first];
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
