<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\Agent\AgentMessage;
use Pi\Agent\ThinkingLevel;
use Pi\AI\Model;

readonly class CodingAgentState implements \JsonSerializable
{
    /**
     * @param  array<AgentMessage>  $messages
     * @param  array<string>  $pendingToolCalls
     * @param  array<string>  $toolNames
     */
    public function __construct(
        public string $sessionId,
        public ?string $sessionPath,
        public string $cwd,
        public ?Model $model,
        public string $systemPrompt,
        public ThinkingLevel $thinkingLevel,
        public array $messages,
        public bool $isStreaming,
        public ?AgentMessage $streamingMessage,
        public array $pendingToolCalls,
        public ?string $errorMessage,
        public array $toolNames,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'sessionPath' => $this->sessionPath,
            'cwd' => $this->cwd,
            'model' => $this->model === null ? null : [
                'provider' => $this->model->provider->value,
                'id' => $this->model->id,
                'api' => $this->model->api->value,
            ],
            'systemPrompt' => $this->systemPrompt,
            'thinkingLevel' => $this->thinkingLevel->value,
            'messages' => $this->messages,
            'isStreaming' => $this->isStreaming,
            'streamingMessage' => $this->streamingMessage,
            'pendingToolCalls' => $this->pendingToolCalls,
            'errorMessage' => $this->errorMessage,
            'toolNames' => $this->toolNames,
        ];
    }
}
