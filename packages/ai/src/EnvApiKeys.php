<?php

declare(strict_types=1);

namespace Pi\AI;

final class EnvApiKeys
{
    /**
     * @return list<string>|null
     */
    public static function getApiKeyEnvVars(string $provider): ?array
    {
        if ($provider === Provider::GITHUB_COPILOT) {
            return ['COPILOT_GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_TOKEN'];
        }

        if ($provider === Provider::ANTHROPIC) {
            return ['ANTHROPIC_OAUTH_TOKEN', 'ANTHROPIC_API_KEY'];
        }

        $map = [
            Provider::OPENAI => 'OPENAI_API_KEY',
            Provider::AZURE_OPENAI_RESPONSES => 'AZURE_OPENAI_API_KEY',
            Provider::DEEPSEEK => 'DEEPSEEK_API_KEY',
            Provider::GOOGLE => 'GEMINI_API_KEY',
            Provider::GOOGLE_VERTEX => 'GOOGLE_CLOUD_API_KEY',
            Provider::GROQ => 'GROQ_API_KEY',
            Provider::CEREBRAS => 'CEREBRAS_API_KEY',
            Provider::XAI => 'XAI_API_KEY',
            Provider::OPENROUTER => 'OPENROUTER_API_KEY',
            Provider::VERCEL_AI_GATEWAY => 'AI_GATEWAY_API_KEY',
            Provider::ZAI => 'ZAI_API_KEY',
            Provider::MISTRAL => 'MISTRAL_API_KEY',
            Provider::MINIMAX => 'MINIMAX_API_KEY',
            Provider::MINIMAX_CN => 'MINIMAX_CN_API_KEY',
            Provider::HUGGINGFACE => 'HF_TOKEN',
            Provider::FIREWORKS => 'FIREWORKS_API_KEY',
            Provider::OPENCODE => 'OPENCODE_API_KEY',
            Provider::OPENCODE_GO => 'OPENCODE_API_KEY',
            Provider::KIMI_CODING => 'KIMI_API_KEY',
        ];

        $envVar = $map[$provider] ?? null;

        return $envVar !== null ? [$envVar] : null;
    }

    /**
     * @return list<string>|null
     */
    public static function findEnvKeys(string $provider): ?array
    {
        $envVars = self::getApiKeyEnvVars($provider);
        if ($envVars === null) {
            return null;
        }

        $found = array_values(array_filter($envVars, static fn (string $envVar): bool => (bool) getenv($envVar)));

        return $found !== [] ? $found : null;
    }

    public static function getEnvApiKey(string $provider): ?string
    {
        $envKeys = self::findEnvKeys($provider);
        if ($envKeys !== null && $envKeys[0] !== '') {
            $value = getenv($envKeys[0]);

            return $value !== false && $value !== '' ? $value : null;
        }

        return null;
    }
}
