<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class Provider
{
    public const AMAZON_BEDROCK = 'amazon-bedrock';

    public const ANTHROPIC = 'anthropic';

    public const GOOGLE = 'google';

    public const GOOGLE_GEMINI_CLI = 'google-gemini-cli';

    public const GOOGLE_ANTIGRAVITY = 'google-antigravity';

    public const GOOGLE_VERTEX = 'google-vertex';

    public const OPENAI = 'openai';

    public const AZURE_OPENAI_RESPONSES = 'azure-openai-responses';

    public const OPENAI_CODEX = 'openai-codex';

    public const DEEPSEEK = 'deepseek';

    public const GITHUB_COPILOT = 'github-copilot';

    public const XAI = 'xai';

    public const GROQ = 'groq';

    public const CEREBRAS = 'cerebras';

    public const OPENROUTER = 'openrouter';

    public const VERCEL_AI_GATEWAY = 'vercel-ai-gateway';

    public const ZAI = 'zai';

    public const MISTRAL = 'mistral';

    public const MINIMAX = 'minimax';

    public const MINIMAX_CN = 'minimax-cn';

    public const HUGGINGFACE = 'huggingface';

    public const FIREWORKS = 'fireworks';

    public const OPENCODE = 'opencode';

    public const OPENCODE_GO = 'opencode-go';

    public const KIMI_CODING = 'kimi-coding';

    public function __construct(
        public string $value,
    ) {}

    public function equals(self|string $provider): bool
    {
        return $this->value === ($provider instanceof self ? $provider->value : $provider);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
