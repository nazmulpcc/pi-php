<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

use Pi\AI\Model;
use React\Promise\PromiseInterface;

interface OAuthProviderInterface
{
    public function getId(): string;

    public function getName(): string;

    public function usesCallbackServer(): bool;

    /**
     * @return PromiseInterface<OAuthCredentials>
     */
    public function login(OAuthLoginCallbacks $callbacks): PromiseInterface;

    /**
     * @return PromiseInterface<OAuthCredentials>
     */
    public function refreshToken(OAuthCredentials $credentials): PromiseInterface;

    public function getApiKey(OAuthCredentials $credentials): string;

    /**
     * @param  array<Model>  $models
     * @return array<Model>
     */
    public function modifyModels(array $models, OAuthCredentials $credentials): array;
}
