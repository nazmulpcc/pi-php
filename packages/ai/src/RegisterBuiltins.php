<?php

declare(strict_types=1);

namespace Pi\AI;

final class RegisterBuiltins
{
    private const SOURCE_ID = 'builtin-providers';

    private static bool $registered = false;

    public static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }

        $factories = [
            Api::OPENAI_RESPONSES => static fn (): ApiProviderInterface => new OpenAI\OpenAIResponsesProvider,
            Api::OPENAI_COMPLETIONS => static fn (): ApiProviderInterface => new OpenAI\Completions\OpenAICompletionsProvider,
            Api::ANTHROPIC_MESSAGES => static fn (): ApiProviderInterface => new Anthropic\AnthropicProvider,
            Api::AZURE_OPENAI_RESPONSES => static fn (): ApiProviderInterface => new Azure\AzureOpenAIResponsesProvider,
        ];

        foreach ($factories as $api => $factory) {
            if (ApiRegistry::getProvider($api) === null) {
                ApiRegistry::registerProviderFactory($api, $factory, self::SOURCE_ID);
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
