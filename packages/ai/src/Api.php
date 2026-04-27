<?php

declare(strict_types=1);

namespace Pi\AI;

readonly class Api
{
    public const OPENAI_COMPLETIONS = 'openai-completions';

    public const MISTRAL_CONVERSATIONS = 'mistral-conversations';

    public const OPENAI_RESPONSES = 'openai-responses';

    public const AZURE_OPENAI_RESPONSES = 'azure-openai-responses';

    public const OPENAI_CODEX_RESPONSES = 'openai-codex-responses';

    public const ANTHROPIC_MESSAGES = 'anthropic-messages';

    public const BEDROCK_CONVERSE_STREAM = 'bedrock-converse-stream';

    public const GOOGLE_GENERATIVE_AI = 'google-generative-ai';

    public const GOOGLE_GEMINI_CLI = 'google-gemini-cli';

    public const GOOGLE_VERTEX = 'google-vertex';

    public function __construct(
        public string $value,
    ) {}

    public function equals(self|string $api): bool
    {
        return $this->value === ($api instanceof self ? $api->value : $api);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
