<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI\Completions;

use Pi\AI\Compat\OpenAICompletionsCompat;
use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\Provider;
use Pi\AI\Schema\Schema;
use Pi\AI\StopReason;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\TransformMessages;
use Pi\AI\Tool;
use Pi\AI\Usage;
use Pi\AI\UsageCost;

final class OpenAICompletionsShared
{
    public static function detectCompat(Model $model): OpenAICompletionsCompat
    {
        $provider = $model->provider->value;
        $baseUrl = $model->baseUrl;

        $isZai = $provider === Provider::ZAI || str_contains($baseUrl, 'api.z.ai');
        $isNonStandard =
            $provider === Provider::CEREBRAS
            || str_contains($baseUrl, 'cerebras.ai')
            || $provider === Provider::XAI
            || str_contains($baseUrl, 'api.x.ai')
            || str_contains($baseUrl, 'chutes.ai')
            || str_contains($baseUrl, 'deepseek.com')
            || $isZai
            || $provider === Provider::OPENCODE
            || str_contains($baseUrl, 'opencode.ai');

        $useMaxTokens = str_contains($baseUrl, 'chutes.ai');
        $isGrok = $provider === Provider::XAI || str_contains($baseUrl, 'api.x.ai');
        $isGroq = $provider === Provider::GROQ || str_contains($baseUrl, 'groq.com');
        $isDeepSeek = $provider === Provider::DEEPSEEK || str_contains($baseUrl, 'deepseek.com');
        $cacheControlFormat = ($provider === Provider::OPENROUTER && str_starts_with($model->id, 'anthropic/')) ? 'anthropic' : null;

        $reasoningEffortMap = [];
        if ($isDeepSeek) {
            $reasoningEffortMap = [
                'minimal' => 'high',
                'low' => 'high',
                'medium' => 'high',
                'high' => 'high',
                'xhigh' => 'max',
            ];
        } elseif ($isGroq && $model->id === 'qwen/qwen3-32b') {
            $reasoningEffortMap = [
                'minimal' => 'default',
                'low' => 'default',
                'medium' => 'default',
                'high' => 'default',
                'xhigh' => 'default',
            ];
        }

        return new OpenAICompletionsCompat(
            supportsStore: ! $isNonStandard,
            supportsDeveloperRole: ! $isNonStandard,
            supportsReasoningEffort: ! $isGrok && ! $isZai,
            reasoningEffortMap: $reasoningEffortMap,
            supportsUsageInStreaming: true,
            maxTokensField: $useMaxTokens ? 'max_tokens' : 'max_completion_tokens',
            requiresToolResultName: false,
            requiresAssistantAfterToolResult: false,
            requiresThinkingAsText: false,
            requiresReasoningContentOnAssistantMessages: $isDeepSeek,
            thinkingFormat: $isDeepSeek
                ? 'deepseek'
                : ($isZai
                    ? 'zai'
                    : (($provider === Provider::OPENROUTER || str_contains($baseUrl, 'openrouter.ai'))
                        ? 'openrouter'
                        : 'openai')),
            openRouterRouting: null,
            vercelGatewayRouting: null,
            zaiToolStream: false,
            supportsStrictMode: true,
            cacheControlFormat: $cacheControlFormat,
            sendSessionAffinityHeaders: false,
            supportsLongCacheRetention: true,
        );
    }

    public static function getCompat(Model $model): OpenAICompletionsCompat
    {
        $detected = self::detectCompat($model);
        $explicit = $model->compat;

        if (! is_array($explicit)) {
            return $detected;
        }

        return new OpenAICompletionsCompat(
            supportsStore: $explicit['supportsStore'] ?? $detected->supportsStore,
            supportsDeveloperRole: $explicit['supportsDeveloperRole'] ?? $detected->supportsDeveloperRole,
            supportsReasoningEffort: $explicit['supportsReasoningEffort'] ?? $detected->supportsReasoningEffort,
            reasoningEffortMap: $explicit['reasoningEffortMap'] ?? $detected->reasoningEffortMap,
            supportsUsageInStreaming: $explicit['supportsUsageInStreaming'] ?? $detected->supportsUsageInStreaming,
            maxTokensField: $explicit['maxTokensField'] ?? $detected->maxTokensField,
            requiresToolResultName: $explicit['requiresToolResultName'] ?? $detected->requiresToolResultName,
            requiresAssistantAfterToolResult: $explicit['requiresAssistantAfterToolResult'] ?? $detected->requiresAssistantAfterToolResult,
            requiresThinkingAsText: $explicit['requiresThinkingAsText'] ?? $detected->requiresThinkingAsText,
            requiresReasoningContentOnAssistantMessages: $explicit['requiresReasoningContentOnAssistantMessages'] ?? $detected->requiresReasoningContentOnAssistantMessages,
            thinkingFormat: $explicit['thinkingFormat'] ?? $detected->thinkingFormat,
            openRouterRouting: $explicit['openRouterRouting'] ?? $detected->openRouterRouting,
            vercelGatewayRouting: $explicit['vercelGatewayRouting'] ?? $detected->vercelGatewayRouting,
            zaiToolStream: $explicit['zaiToolStream'] ?? $detected->zaiToolStream,
            supportsStrictMode: $explicit['supportsStrictMode'] ?? $detected->supportsStrictMode,
            cacheControlFormat: $explicit['cacheControlFormat'] ?? $detected->cacheControlFormat,
            sendSessionAffinityHeaders: $explicit['sendSessionAffinityHeaders'] ?? $detected->sendSessionAffinityHeaders,
            supportsLongCacheRetention: $explicit['supportsLongCacheRetention'] ?? $detected->supportsLongCacheRetention,
        );
    }

    public static function hasToolHistory(array $messages): bool
    {
        foreach ($messages as $msg) {
            if ($msg instanceof ToolResultMessage) {
                return true;
            }
            if ($msg instanceof AssistantMessage) {
                foreach ($msg->content as $block) {
                    if ($block instanceof ToolCall) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function convertMessages(Model $model, Context $context, OpenAICompletionsCompat $compat): array
    {
        $params = [];

        $normalizeToolCallId = function (string $id) use ($model): string {
            if (str_contains($id, '|')) {
                [$callId] = explode('|', $id, 2);

                return self::truncateId(preg_replace('/[^a-zA-Z0-9_-]/', '_', $callId) ?? $callId);
            }

            if ($model->provider->value === Provider::OPENAI) {
                return strlen($id) > 40 ? substr($id, 0, 40) : $id;
            }

            return self::truncateId(preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? $id);
        };

        $transformed = TransformMessages::transformMessages($context->messages, $model, $normalizeToolCallId);

        if ($context->systemPrompt !== null && $context->systemPrompt !== '') {
            $role = ($model->reasoning && $compat->supportsDeveloperRole) ? 'developer' : 'system';
            $params[] = ['role' => $role, 'content' => SanitizeUnicode::sanitizeSurrogates($context->systemPrompt)];
        }

        $lastRole = null;

        foreach ($transformed as $msg) {
            if ($compat->requiresAssistantAfterToolResult && $lastRole === 'toolResult' && $msg instanceof UserMessage) {
                $params[] = ['role' => 'assistant', 'content' => 'I have processed the tool results.'];
            }

            if ($msg instanceof UserMessage) {
                if (is_string($msg->content)) {
                    $params[] = ['role' => 'user', 'content' => SanitizeUnicode::sanitizeSurrogates($msg->content)];
                } else {
                    $content = [];
                    foreach ($msg->content as $item) {
                        if ($item instanceof TextContent) {
                            $content[] = ['type' => 'text', 'text' => SanitizeUnicode::sanitizeSurrogates($item->text)];
                        } elseif ($item instanceof ImageContent) {
                            $content[] = [
                                'type' => 'image_url',
                                'image_url' => ['url' => sprintf('data:%s;base64,%s', $item->mimeType, $item->data)],
                            ];
                        }
                    }
                    if ($content === []) {
                        continue;
                    }
                    $params[] = ['role' => 'user', 'content' => $content];
                }
            } elseif ($msg instanceof AssistantMessage) {
                $assistantMsg = [
                    'role' => 'assistant',
                    'content' => $compat->requiresAssistantAfterToolResult ? '' : null,
                ];

                $textParts = [];
                $thinkingParts = [];
                $toolCalls = [];

                foreach ($msg->content as $block) {
                    if ($block instanceof TextContent && trim($block->text) !== '') {
                        $textParts[] = SanitizeUnicode::sanitizeSurrogates($block->text);
                    } elseif ($block instanceof ThinkingContent && trim($block->thinking) !== '') {
                        $thinkingParts[] = $block;
                    } elseif ($block instanceof ToolCall) {
                        $toolCalls[] = $block;
                    }
                }

                $assistantText = implode('', $textParts);

                if ($thinkingParts !== []) {
                    if ($compat->requiresThinkingAsText) {
                        $thinkingText = implode("\n\n", array_map(
                            static fn (ThinkingContent $t): string => SanitizeUnicode::sanitizeSurrogates($t->thinking),
                            $thinkingParts,
                        ));
                        $assistantMsg['content'] = array_merge(
                            [['type' => 'text', 'text' => $thinkingText]],
                            array_map(static fn (string $t): array => ['type' => 'text', 'text' => $t], $textParts),
                        );
                    } else {
                        if ($assistantText !== '') {
                            $assistantMsg['content'] = $assistantText;
                        }
                        $signature = $thinkingParts[0]->thinkingSignature ?? '';
                        if ($signature !== '' && $signature !== '0') {
                            $assistantMsg[$signature] = implode("\n", array_map(
                                static fn (ThinkingContent $t): string => $t->thinking,
                                $thinkingParts,
                            ));
                        }
                    }
                } elseif ($assistantText !== '') {
                    $assistantMsg['content'] = $assistantText;
                }

                if ($toolCalls !== []) {
                    $assistantMsg['tool_calls'] = array_map(static fn (ToolCall $tc): array => [
                        'id' => $tc->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $tc->name,
                            'arguments' => json_encode($tc->arguments, JSON_THROW_ON_ERROR),
                        ],
                    ], $toolCalls);
                }

                if ($compat->requiresReasoningContentOnAssistantMessages && $model->reasoning && ! isset($assistantMsg['reasoning_content'])) {
                    $assistantMsg['reasoning_content'] = '';
                }

                $hasContent = ($assistantMsg['content'] !== null && $assistantMsg['content'] !== '' && $assistantMsg['content'] !== []);
                if (! $hasContent && ! isset($assistantMsg['tool_calls'])) {
                    continue;
                }

                $params[] = $assistantMsg;
            } elseif ($msg instanceof ToolResultMessage) {
                $textResult = implode("\n", array_map(
                    static fn (TextContent $c): string => $c->text,
                    array_values(array_filter($msg->content, static fn ($c): bool => $c instanceof TextContent)),
                ));
                $hasImages = false;
                foreach ($msg->content as $c) {
                    if ($c instanceof ImageContent) {
                        $hasImages = true;
                        break;
                    }
                }

                $toolResultMsg = [
                    'role' => 'tool',
                    'content' => SanitizeUnicode::sanitizeSurrogates($textResult !== '' ? $textResult : '(see attached image)'),
                    'tool_call_id' => $msg->toolCallId,
                ];
                if ($compat->requiresToolResultName && $msg->toolName !== '') {
                    $toolResultMsg['name'] = $msg->toolName;
                }
                $params[] = $toolResultMsg;

                if ($hasImages && in_array('image', $model->input, true)) {
                    $imageBlocks = [];
                    foreach ($msg->content as $block) {
                        if ($block instanceof ImageContent) {
                            $imageBlocks[] = [
                                'type' => 'image_url',
                                'image_url' => ['url' => sprintf('data:%s;base64,%s', $block->mimeType, $block->data)],
                            ];
                        }
                    }
                    if ($imageBlocks !== []) {
                        if ($compat->requiresAssistantAfterToolResult) {
                            $params[] = ['role' => 'assistant', 'content' => 'I have processed the tool results.'];
                        }
                        $params[] = [
                            'role' => 'user',
                            'content' => array_merge(
                                [['type' => 'text', 'text' => 'Attached image(s) from tool result:']],
                                $imageBlocks,
                            ),
                        ];
                        $lastRole = 'user';

                        continue;
                    }
                }

                $lastRole = 'toolResult';

                continue;
            }

            $lastRole = $msg instanceof UserMessage ? 'user' : ($msg instanceof AssistantMessage ? 'assistant' : 'toolResult');
        }

        return $params;
    }

    public static function convertTools(array $tools, OpenAICompletionsCompat $compat): array
    {
        return array_map(static fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parameters instanceof Schema ? $tool->parameters->toArray() : $tool->parameters,
                ...($compat->supportsStrictMode !== false ? ['strict' => false] : []),
            ],
        ], $tools);
    }

    public static function parseChunkUsage(array $rawUsage, Model $model): Usage
    {
        $promptTokens = $rawUsage['prompt_tokens'] ?? 0;
        $reportedCachedTokens = $rawUsage['prompt_tokens_details']['cached_tokens'] ?? 0;
        $cacheWriteTokens = $rawUsage['prompt_tokens_details']['cache_write_tokens'] ?? 0;

        $cacheReadTokens = $cacheWriteTokens > 0
            ? max(0, $reportedCachedTokens - $cacheWriteTokens)
            : $reportedCachedTokens;

        $input = max(0, $promptTokens - $cacheReadTokens - $cacheWriteTokens);
        $outputTokens = $rawUsage['completion_tokens'] ?? 0;
        $usage = new Usage(
            input: $input,
            output: $outputTokens,
            cacheRead: $cacheReadTokens,
            cacheWrite: $cacheWriteTokens,
            totalTokens: $input + $outputTokens + $cacheReadTokens + $cacheWriteTokens,
            cost: new UsageCost,
        );
        Models::calculateCost($model, $usage);

        return $usage;
    }

    public static function mapStopReason(?string $reason): array
    {
        if ($reason === null) {
            return ['stopReason' => StopReason::Stop];
        }

        return match ($reason) {
            'stop', 'end' => ['stopReason' => StopReason::Stop],
            'length' => ['stopReason' => StopReason::Length],
            'function_call', 'tool_calls' => ['stopReason' => StopReason::ToolUse],
            'content_filter' => ['stopReason' => StopReason::Error, 'errorMessage' => 'Provider finish_reason: content_filter'],
            'network_error' => ['stopReason' => StopReason::Error, 'errorMessage' => 'Provider finish_reason: network_error'],
            default => ['stopReason' => StopReason::Error, 'errorMessage' => sprintf('Provider finish_reason: %s', $reason)],
        };
    }

    private static function truncateId(string $id): string
    {
        return substr($id, 0, 40);
    }
}
