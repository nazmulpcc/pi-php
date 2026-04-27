<?php

declare(strict_types=1);

namespace Pi\AI;

use Pi\AI\Anthropic\AnthropicProvider;
use Pi\AI\Azure\AzureOpenAIResponsesProvider;
use Pi\AI\OpenAI\Completions\OpenAICompletionsProvider;
use Pi\AI\OpenAI\OpenAIResponsesProvider;

final class RegisterBuiltins
{
    private const SOURCE_ID = 'builtin-providers';

    private static bool $registered = false;

    public static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }

        $providers = [
            new OpenAIResponsesProvider,
            new OpenAICompletionsProvider,
            new AnthropicProvider,
            new AzureOpenAIResponsesProvider,
        ];

        foreach ($providers as $provider) {
            if (ApiRegistry::getProvider($provider->getApi()) === null) {
                ApiRegistry::registerProvider($provider, self::SOURCE_ID);
            }
        }

        self::$registered = true;
    }

    public static function reset(): void
    {
        ApiRegistry::unregisterProviders(self::SOURCE_ID);
        self::$registered = false;
    }
}
