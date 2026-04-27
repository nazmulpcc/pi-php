<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Event;

final readonly class CodingAgentEvent implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload = [],
    ) {}

    public function jsonSerialize(): array
    {
        return ['type' => $this->type, ...$this->payload];
    }
}
