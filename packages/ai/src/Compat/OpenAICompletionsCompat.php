<?php

declare(strict_types=1);

namespace Pi\AI\Compat;

use Pi\AI\Routing\OpenRouterRouting;
use Pi\AI\Routing\VercelGatewayRouting;

readonly class OpenAICompletionsCompat
{
    /**
     * @param  array<string, string>|null  $reasoningEffortMap  Map from thinking level values to provider-specific effort strings.
     */
    public function __construct(
        public ?bool $supportsStore = null,
        public ?bool $supportsDeveloperRole = null,
        public ?bool $supportsReasoningEffort = null,
        public ?array $reasoningEffortMap = null,
        public ?bool $supportsUsageInStreaming = null,
        public ?string $maxTokensField = null,
        public ?bool $requiresToolResultName = null,
        public ?bool $requiresAssistantAfterToolResult = null,
        public ?bool $requiresThinkingAsText = null,
        public ?bool $requiresReasoningContentOnAssistantMessages = null,
        public ?string $thinkingFormat = null,
        public ?OpenRouterRouting $openRouterRouting = null,
        public ?VercelGatewayRouting $vercelGatewayRouting = null,
        public ?bool $zaiToolStream = null,
        public ?bool $supportsStrictMode = null,
        public ?string $cacheControlFormat = null,
        public ?bool $sendSessionAffinityHeaders = null,
        public ?bool $supportsLongCacheRetention = null,
    ) {}
}
