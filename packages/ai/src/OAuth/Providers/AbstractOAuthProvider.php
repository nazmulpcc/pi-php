<?php

declare(strict_types=1);

namespace Pi\AI\OAuth\Providers;

use Pi\AI\Model;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthLoginCallbacks;
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
}
