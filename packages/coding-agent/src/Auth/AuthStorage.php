<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Auth;

use Pi\AI\Model;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use Pi\AI\OAuth\OAuthProviderInterface;
use Pi\AI\Support\PromiseHelper;
use Pi\CodingAgent\Config;
use Pi\CodingAgent\Diagnostics\Diagnostic;
use React\Promise\PromiseInterface;

use function Pi\AI\getEnvApiKey;
use function Pi\AI\getOAuthApiKey;
use function Pi\AI\getOAuthProvider;
use function Pi\AI\getOAuthProviders;
use function React\Promise\resolve;

final class AuthStorage
{
    /** @var array<string, array<string, mixed>> */
    private array $data = [];

    /** @var array<string, string> */
    private array $runtimeOverrides = [];

    private mixed $fallbackResolver = null;

    private ?\Throwable $loadError = null;

    /** @var list<Diagnostic> */
    private array $diagnostics = [];

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
        $this->diagnostics = [];

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
            $this->diagnostics[] = new Diagnostic('auth', $error->getMessage(), 'error', 'load');
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
     * @return list<Diagnostic>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param  array<string, mixed>|null  $credential
     */
    public function set(string $provider, ?array $credential): void
    {
        try {
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
        } catch (\Throwable $error) {
            $this->diagnostics[] = new Diagnostic('auth', sprintf('Failed to update auth for %s: %s', $provider, $error->getMessage()), 'error', $provider);

            throw $error;
        }

        $this->reload();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $provider): ?array
    {
        return isset($this->data[$provider]) && is_array($this->data[$provider]) ? $this->data[$provider] : null;
    }

    /**
     * @return PromiseInterface<?string>
     */
    public function getApiKey(string $provider, array $options = []): PromiseInterface
    {
        if (isset($this->runtimeOverrides[$provider]) && $this->runtimeOverrides[$provider] !== '') {
            return resolve($this->runtimeOverrides[$provider]);
        }

        $stored = $this->get($provider);
        if (is_array($stored)) {
            if (($stored['type'] ?? null) === 'api_key' && is_string($stored['key'] ?? null) && $stored['key'] !== '') {
                return resolve($stored['key']);
            }

            if (($stored['type'] ?? null) === 'oauth') {
                return $this->refreshOAuthTokenWithLock($provider);
            }
        }

        $envKey = getEnvApiKey($provider);
        if ($envKey !== null && $envKey !== '') {
            return resolve($envKey);
        }

        if (($options['includeFallback'] ?? true) && $this->fallbackResolver !== null) {
            return PromiseHelper::resolve(($this->fallbackResolver)($provider))
                ->then(static fn (mixed $resolved): ?string => is_string($resolved) && $resolved !== '' ? $resolved : null);
        }

        return resolve(null);
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
            return ['configured' => false, 'source' => 'fallback', 'label' => 'Fallback resolver'];
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
     * @return array<OAuthProviderInterface>
     */
    public function getOAuthProviders(): array
    {
        return getOAuthProviders();
    }

    /**
     * @return PromiseInterface<void>
     */
    public function login(string $providerId, OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        $provider = getOAuthProvider($providerId);
        if ($provider === null) {
            return PromiseHelper::reject(new \RuntimeException(sprintf('Unknown OAuth provider: %s', $providerId)));
        }

        return $provider->login($callbacks)
            ->then(function (OAuthCredentials $credentials) use ($providerId): void {
                $this->set($providerId, [
                    'type' => 'oauth',
                    ...$credentials->toArray(),
                ]);
            }, function (mixed $error) use ($providerId): PromiseInterface {
                $error = PromiseHelper::normalizeThrowable($error);
                $this->diagnostics[] = new Diagnostic('auth', sprintf('OAuth login failed for %s: %s', $providerId, $error->getMessage()), 'error', $providerId);

                return PromiseHelper::reject($error);
            });
    }

    public function logout(string $provider): void
    {
        $this->set($provider, null);
    }

    /**
     * @param  array<Model>  $models
     * @return array<Model>
     */
    public function modifyModels(array $models): array
    {
        $modified = $models;

        foreach ($this->getOAuthProviders() as $provider) {
            $credential = $this->get($provider->getId());
            if (! is_array($credential) || ($credential['type'] ?? null) !== 'oauth') {
                continue;
            }

            $modified = $provider->modifyModels($modified, OAuthCredentials::fromArray($credential));
        }

        return $modified;
    }

    /**
     * @return PromiseInterface<?string>
     */
    private function refreshOAuthTokenWithLock(string $provider): PromiseInterface
    {
        return $this->storage->withLockAsync(function (?string $current) use ($provider) {
            $data = $this->decode($current);
            $credential = $data[$provider] ?? null;
            if (! is_array($credential) || ($credential['type'] ?? null) !== 'oauth') {
                return resolve(['result' => null]);
            }

            if ((int) ($credential['expires'] ?? 0) > (time() * 1000)) {
                $oauthCredentials = OAuthCredentials::fromArray($credential);

                return resolve(['result' => $oauthCredentials->access]);
            }

            return getOAuthApiKey($provider, [
                $provider => OAuthCredentials::fromArray($credential),
            ])->then(function (?array $result) use ($data, $provider): array {
                if ($result === null) {
                    return ['result' => null];
                }

                $merged = [
                    ...$data,
                    $provider => [
                        'type' => 'oauth',
                        ...$result['newCredentials']->toArray(),
                    ],
                ];

                return [
                    'result' => $result['apiKey'],
                    'next' => json_encode($merged, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                ];
            });
        })->then(function (?string $apiKey): ?string {
            $this->reload();

            return $apiKey;
        }, function (mixed $error) use ($provider): PromiseInterface {
            $this->reload();
            $updatedCredential = $this->data[$provider] ?? null;
            if (is_array($updatedCredential) && ($updatedCredential['type'] ?? null) === 'oauth' && (int) ($updatedCredential['expires'] ?? 0) > (time() * 1000)) {
                return resolve(is_string($updatedCredential['access'] ?? null) ? $updatedCredential['access'] : null);
            }

            $error = PromiseHelper::normalizeThrowable($error);
            $this->diagnostics[] = new Diagnostic('auth', sprintf('OAuth refresh failed for %s: %s', $provider, $error->getMessage()), 'error', $provider);

            return PromiseHelper::reject(PromiseHelper::normalizeThrowable($error));
        });
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
