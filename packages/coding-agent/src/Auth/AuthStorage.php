<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Auth;

use Pi\CodingAgent\Config;

use function Pi\AI\getEnvApiKey;

final class AuthStorage
{
    /** @var array<string, array<string, mixed>> */
    private array $data = [];

    /** @var array<string, string> */
    private array $runtimeOverrides = [];

    private mixed $fallbackResolver = null;

    private ?\Throwable $loadError = null;

    private function __construct(
        private readonly AuthStorageBackend $storage,
    ) {
        $this->reload();
    }

    public static function create(?string $authPath = null): self
    {
        return new self(new FileAuthStorageBackend($authPath ?? Config::getAgentDir().'/auth.json'));
    }

    public static function fromBackend(AuthStorageBackend $backend): self
    {
        return new self($backend);
    }

    /**
     * @param  array<string, array<string, mixed>>  $data
     */
    public static function inMemory(array $data = []): self
    {
        $backend = new InMemoryAuthStorageBackend;
        $backend->withLock(static fn () => ['result' => null, 'next' => json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)]);

        return new self($backend);
    }

    public function reload(): void
    {
        $this->loadError = null;

        try {
            $decoded = $this->storage->withLock(function (?string $current): array {
                if ($current === null || trim($current) === '') {
                    return ['result' => []];
                }

                $data = json_decode($current, true, 512, JSON_THROW_ON_ERROR);

                return ['result' => is_array($data) ? $data : []];
            });
            $this->data = is_array($decoded) ? $decoded : [];
        } catch (\Throwable $error) {
            $this->data = [];
            $this->loadError = $error;
        }
    }

    public function getLoadError(): ?\Throwable
    {
        return $this->loadError;
    }

    public function setRuntimeApiKey(string $provider, string $apiKey): void
    {
        $this->runtimeOverrides[$provider] = $apiKey;
    }

    public function removeRuntimeApiKey(string $provider): void
    {
        unset($this->runtimeOverrides[$provider]);
    }

    public function setFallbackResolver(?callable $resolver): void
    {
        $this->fallbackResolver = $resolver;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @param  array<string, mixed>|null  $credential
     */
    public function set(string $provider, ?array $credential): void
    {
        $this->storage->withLock(function (?string $current) use ($provider, $credential): array {
            $data = $this->decode($current);
            if ($credential === null) {
                unset($data[$provider]);
            } else {
                $data[$provider] = $credential;
            }

            return [
                'result' => null,
                'next' => json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ];
        });

        $this->reload();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $provider): ?array
    {
        return isset($this->data[$provider]) && is_array($this->data[$provider]) ? $this->data[$provider] : null;
    }

    public function getApiKey(string $provider): ?string
    {
        if (isset($this->runtimeOverrides[$provider]) && $this->runtimeOverrides[$provider] !== '') {
            return $this->runtimeOverrides[$provider];
        }

        $stored = $this->get($provider);
        if (is_array($stored)) {
            if (($stored['type'] ?? null) === 'api_key' && is_string($stored['key'] ?? null) && $stored['key'] !== '') {
                return $stored['key'];
            }

            if (($stored['type'] ?? null) === 'oauth' && is_string($stored['accessToken'] ?? null) && $stored['accessToken'] !== '') {
                return $stored['accessToken'];
            }
        }

        $envKey = getEnvApiKey($provider);
        if ($envKey !== null && $envKey !== '') {
            return $envKey;
        }

        if ($this->fallbackResolver !== null) {
            $resolved = ($this->fallbackResolver)($provider);

            return is_string($resolved) && $resolved !== '' ? $resolved : null;
        }

        return null;
    }

    /**
     * @return array{configured: bool, source?: string, label?: string}
     */
    public function getStatus(string $provider): array
    {
        if (isset($this->runtimeOverrides[$provider]) && $this->runtimeOverrides[$provider] !== '') {
            return ['configured' => true, 'source' => 'runtime', 'label' => 'Runtime API key'];
        }

        $stored = $this->get($provider);
        if (is_array($stored)) {
            if (($stored['type'] ?? null) === 'api_key') {
                return ['configured' => true, 'source' => 'stored', 'label' => 'Stored API key'];
            }

            if (($stored['type'] ?? null) === 'oauth') {
                return ['configured' => true, 'source' => 'stored', 'label' => 'Stored OAuth token'];
            }
        }

        if (($envKey = getEnvApiKey($provider)) !== null && $envKey !== '') {
            return ['configured' => true, 'source' => 'environment', 'label' => 'Environment variable'];
        }

        if ($this->fallbackResolver !== null) {
            $resolved = ($this->fallbackResolver)($provider);
            if (is_string($resolved) && $resolved !== '') {
                return ['configured' => true, 'source' => 'fallback', 'label' => 'Fallback resolver'];
            }
        }

        return ['configured' => false];
    }

    /**
     * @return list<string>
     */
    public function list(): array
    {
        $providers = array_keys($this->data);
        sort($providers);

        return $providers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decode(?string $current): array
    {
        if ($current === null || trim($current) === '') {
            return [];
        }

        $decoded = json_decode($current, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
