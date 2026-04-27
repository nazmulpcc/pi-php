<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

readonly class OAuthCredentials
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $refresh,
        public string $access,
        public int $expires,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $refresh = is_string($data['refresh'] ?? null) ? $data['refresh'] : '';
        $access = is_string($data['access'] ?? null) ? $data['access'] : '';
        $expires = is_int($data['expires'] ?? null) ? $data['expires'] : (int) ($data['expires'] ?? 0);
        $extra = $data;
        unset($extra['refresh'], $extra['access'], $extra['expires'], $extra['type']);

        return new self($refresh, $access, $expires, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'refresh' => $this->refresh,
            'access' => $this->access,
            'expires' => $this->expires,
            ...$this->extra,
        ];
    }

    public function get(string $key): mixed
    {
        return $this->extra[$key] ?? null;
    }
}
