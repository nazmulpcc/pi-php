<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Diagnostics;

readonly class Diagnostic implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $source,
        public string $message,
        public string $severity = 'error',
        public ?string $scope = null,
        public ?string $path = null,
        public array $context = [],
    ) {}

    public function jsonSerialize(): array
    {
        $payload = [
            'source' => $this->source,
            'message' => $this->message,
            'severity' => $this->severity,
        ];

        if ($this->scope !== null) {
            $payload['scope'] = $this->scope;
        }

        if ($this->path !== null) {
            $payload['path'] = $this->path;
        }

        if ($this->context !== []) {
            $payload['context'] = $this->context;
        }

        return $payload;
    }
}
