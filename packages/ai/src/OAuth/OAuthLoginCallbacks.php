<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

use Pi\AI\CancellationToken;
use React\Promise\PromiseInterface;

readonly class OAuthLoginCallbacks
{
    /**
     * @param  callable(OAuthAuthInfo): void  $onAuth
     * @param  callable(OAuthPrompt): PromiseInterface<string>|string  $onPrompt
     * @param  null|callable(string): void  $onProgress
     * @param  null|callable(): PromiseInterface<string>|string  $onManualCodeInput
     */
    public function __construct(
        public mixed $onAuth,
        public mixed $onPrompt,
        public mixed $onProgress = null,
        public mixed $onManualCodeInput = null,
        public ?CancellationToken $signal = null,
    ) {}
}
