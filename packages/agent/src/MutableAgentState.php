<?php

declare(strict_types=1);

namespace Pi\Agent;

class MutableAgentState implements AgentState
{
    private array $tools = [];

    private array $messages = [];

    private bool $isStreaming = false;

    private ?AgentMessage $streamingMessage = null;

    private array $pendingToolCalls = [];

    private ?string $errorMessage = null;

    public function __construct(
        private string $systemPrompt = '',
        private ThinkingLevel $thinkingLevel = ThinkingLevel::Off,
        array $tools = [],
        array $messages = [],
    ) {
        $this->tools = $tools;
        $this->messages = $messages;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(string $systemPrompt): void
    {
        $this->systemPrompt = $systemPrompt;
    }

    public function getThinkingLevel(): ThinkingLevel
    {
        return $this->thinkingLevel;
    }

    public function setThinkingLevel(ThinkingLevel $thinkingLevel): void
    {
        $this->thinkingLevel = $thinkingLevel;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function setTools(array $tools): void
    {
        $this->tools = $tools;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function isStreaming(): bool
    {
        return $this->isStreaming;
    }

    public function setIsStreaming(bool $isStreaming): void
    {
        $this->isStreaming = $isStreaming;
    }

    public function getStreamingMessage(): ?AgentMessage
    {
        return $this->streamingMessage;
    }

    public function setStreamingMessage(?AgentMessage $streamingMessage): void
    {
        $this->streamingMessage = $streamingMessage;
    }

    public function getPendingToolCalls(): array
    {
        return $this->pendingToolCalls;
    }

    public function setPendingToolCalls(array $pendingToolCalls): void
    {
        $this->pendingToolCalls = $pendingToolCalls;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }
}
