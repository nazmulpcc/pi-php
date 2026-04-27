<?php

declare(strict_types=1);

namespace Pi\AI;

final class ModelCatalog
{
    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function seed(): array
    {
        return [
            Provider::OPENAI => [
                'gpt-5-mini' => [
                    'id' => 'gpt-5-mini',
                    'name' => 'GPT-5 Mini',
                    'api' => Api::OPENAI_RESPONSES,
                    'provider' => Provider::OPENAI,
                    'baseUrl' => 'https://api.openai.com/v1',
                    'reasoning' => true,
                    'input' => ['text', 'image'],
                    'cost' => [
                        'input' => 0.25,
                        'output' => 2.0,
                        'cacheRead' => 0.025,
                        'cacheWrite' => 0.25,
                    ],
                    'contextWindow' => 400000,
                    'maxTokens' => 128000,
                ],
                'gpt-4o-mini' => [
                    'id' => 'gpt-4o-mini',
                    'name' => 'GPT-4o Mini',
                    'api' => Api::OPENAI_RESPONSES,
                    'provider' => Provider::OPENAI,
                    'baseUrl' => 'https://api.openai.com/v1',
                    'reasoning' => false,
                    'input' => ['text', 'image'],
                    'cost' => [
                        'input' => 0.15,
                        'output' => 0.6,
                        'cacheRead' => 0.015,
                        'cacheWrite' => 0.15,
                    ],
                    'contextWindow' => 128000,
                    'maxTokens' => 16384,
                ],
            ],
            Provider::ANTHROPIC => [
                'claude-opus-4-6' => [
                    'id' => 'claude-opus-4-6',
                    'name' => 'Claude Opus 4.6',
                    'api' => Api::ANTHROPIC_MESSAGES,
                    'provider' => Provider::ANTHROPIC,
                    'baseUrl' => 'https://api.anthropic.com',
                    'reasoning' => true,
                    'input' => ['text', 'image'],
                    'cost' => [
                        'input' => 15.0,
                        'output' => 75.0,
                        'cacheRead' => 1.5,
                        'cacheWrite' => 18.75,
                    ],
                    'contextWindow' => 200000,
                    'maxTokens' => 32000,
                ],
                'claude-opus-4-7' => [
                    'id' => 'claude-opus-4-7',
                    'name' => 'Claude Opus 4.7',
                    'api' => Api::ANTHROPIC_MESSAGES,
                    'provider' => Provider::ANTHROPIC,
                    'baseUrl' => 'https://api.anthropic.com',
                    'reasoning' => true,
                    'input' => ['text', 'image'],
                    'cost' => [
                        'input' => 15.0,
                        'output' => 75.0,
                        'cacheRead' => 1.5,
                        'cacheWrite' => 18.75,
                    ],
                    'contextWindow' => 200000,
                    'maxTokens' => 32000,
                ],
                'claude-sonnet-4-5' => [
                    'id' => 'claude-sonnet-4-5',
                    'name' => 'Claude Sonnet 4.5',
                    'api' => Api::ANTHROPIC_MESSAGES,
                    'provider' => Provider::ANTHROPIC,
                    'baseUrl' => 'https://api.anthropic.com',
                    'reasoning' => true,
                    'input' => ['text', 'image'],
                    'cost' => [
                        'input' => 3.0,
                        'output' => 15.0,
                        'cacheRead' => 0.3,
                        'cacheWrite' => 3.75,
                    ],
                    'contextWindow' => 200000,
                    'maxTokens' => 64000,
                ],
            ],
            Provider::OPENROUTER => [
                'anthropic/claude-opus-4.6' => [
                    'id' => 'anthropic/claude-opus-4.6',
                    'name' => 'Claude Opus 4.6 via OpenRouter',
                    'api' => Api::OPENAI_COMPLETIONS,
                    'provider' => Provider::OPENROUTER,
                    'baseUrl' => 'https://openrouter.ai/api/v1',
                    'reasoning' => true,
                    'input' => ['text', 'image'],
                    'cost' => [
                        'input' => 15.0,
                        'output' => 75.0,
                        'cacheRead' => 1.5,
                        'cacheWrite' => 18.75,
                    ],
                    'contextWindow' => 200000,
                    'maxTokens' => 32000,
                ],
            ],
            Provider::OPENAI_CODEX => [
                'gpt-5.4' => [
                    'id' => 'gpt-5.4',
                    'name' => 'GPT-5.4 Codex',
                    'api' => Api::OPENAI_CODEX_RESPONSES,
                    'provider' => Provider::OPENAI_CODEX,
                    'baseUrl' => 'https://api.openai.com/v1',
                    'reasoning' => true,
                    'input' => ['text'],
                    'cost' => [
                        'input' => 2.0,
                        'output' => 8.0,
                        'cacheRead' => 0.2,
                        'cacheWrite' => 2.0,
                    ],
                    'contextWindow' => 256000,
                    'maxTokens' => 128000,
                ],
                'gpt-5.5' => [
                    'id' => 'gpt-5.5',
                    'name' => 'GPT-5.5 Codex',
                    'api' => Api::OPENAI_CODEX_RESPONSES,
                    'provider' => Provider::OPENAI_CODEX,
                    'baseUrl' => 'https://api.openai.com/v1',
                    'reasoning' => true,
                    'input' => ['text'],
                    'cost' => [
                        'input' => 2.5,
                        'output' => 10.0,
                        'cacheRead' => 0.25,
                        'cacheWrite' => 2.5,
                    ],
                    'contextWindow' => 256000,
                    'maxTokens' => 128000,
                ],
            ],
        ];
    }

    public static function render(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

return 
PHP.var_export(self::seed(), true).";\n";
    }
}
