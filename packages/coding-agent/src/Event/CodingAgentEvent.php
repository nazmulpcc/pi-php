<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Event;

readonly class CodingAgentEvent implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public string $sessionId,
        public int $timestamp,
        public array $data = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'sessionId' => $this->sessionId,
            'timestamp' => $this->timestamp,
            'data' => $this->data,
        ];
    }
}
