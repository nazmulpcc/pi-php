<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\Model;
use Pi\AI\OAuth\OAuthAuthInfo;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use Pi\AI\OAuth\OAuthPrompt;
use Pi\AI\OAuth\OAuthProviderInterface;
use Pi\AI\Support\PromiseHelper;
use React\Promise\PromiseInterface;

abstract class AbstractOAuthProvider implements OAuthProviderInterface
{
    public function usesCallbackServer(): bool
    {
        return false;
    }

    public function login(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        return PromiseHelper::reject(new \RuntimeException(sprintf(
            '%s login flow is deferred in the PHP port. Persist credentials directly in auth.json for now.',
            $this->getId(),
        )));
    }

    /**
     * @param  array<Model>  $models
     * @return array<Model>
     */
    public function modifyModels(array $models, OAuthCredentials $credentials): array
    {
        return $models;
    }

    protected function onAuth(OAuthLoginCallbacks $callbacks, string $url, ?string $instructions = null): void
    {
        ($callbacks->onAuth)(new OAuthAuthInfo($url, $instructions));
    }

    /**
     * @return PromiseInterface<string>
     */
    protected function prompt(OAuthLoginCallbacks $callbacks, string $message, ?string $placeholder = null, bool $allowEmpty = false): PromiseInterface
    {
        return PromiseHelper::resolve(($callbacks->onPrompt)(new OAuthPrompt($message, $placeholder, $allowEmpty)))
            ->then(static fn (mixed $value): string => is_string($value) ? $value : '');
    }

    protected function progress(OAuthLoginCallbacks $callbacks, string $message): void
    {
        if ($callbacks->onProgress !== null) {
            ($callbacks->onProgress)($message);
        }
    }

    /**
     * @return PromiseInterface<string>
     */
    protected function manualCodeInput(OAuthLoginCallbacks $callbacks): PromiseInterface
    {
        if ($callbacks->onManualCodeInput === null) {
            return PromiseHelper::resolve('');
        }

        return PromiseHelper::resolve(($callbacks->onManualCodeInput)())
            ->then(static fn (mixed $value): string => is_string($value) ? $value : '');
    }
}
