<?php

declare(strict_types=1);

namespace Pi\Agent;

use Pi\Agent\Tool\AgentTool;

interface AgentState
{
    public function getSystemPrompt(): string;

    public function getThinkingLevel(): ThinkingLevel;

    /**
     * @return array<AgentTool>
     */
    public function getTools(): array;

    public function setTools(array $tools): void;

    /**
     * @return array<AgentMessage>
     */
    public function getMessages(): array;

    public function setMessages(array $messages): void;

    public function isStreaming(): bool;

    public function getStreamingMessage(): ?AgentMessage;

    /**
     * @return array<string>
     */
    public function getPendingToolCalls(): array;

    public function getErrorMessage(): ?string;
}
