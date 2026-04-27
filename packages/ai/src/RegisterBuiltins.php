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
            Api::MISTRAL_CONVERSATIONS => static fn (): ApiProviderInterface => new Mistral\MistralProvider,
            Api::BEDROCK_CONVERSE_STREAM => static fn (): ApiProviderInterface => new Bedrock\BedrockProvider,
            Api::AZURE_OPENAI_RESPONSES => static fn (): ApiProviderInterface => new Azure\AzureOpenAIResponsesProvider,
            Api::GOOGLE_GENERATIVE_AI => static fn (): ApiProviderInterface => new Google\GoogleProvider,
            Api::GOOGLE_GEMINI_CLI => static fn (): ApiProviderInterface => new Google\GeminiCli\GoogleGeminiCliProvider,
            Api::GOOGLE_VERTEX => static fn (): ApiProviderInterface => new Google\Vertex\GoogleVertexProvider,
            Api::OPENAI_CODEX_RESPONSES => static fn (): ApiProviderInterface => new OpenAI\Codex\OpenAICodexResponsesProvider,
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
