<?php

declare(strict_types=1);

namespace Pi\AI\Google;

use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Schema\Schema;
use Pi\AI\StopReason;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\TransformMessages;
use Pi\AI\ThinkingLevel;
use Pi\AI\Tool;

final class GoogleShared
{
    private const SKIP_THOUGHT_SIGNATURE = 'skip_thought_signature_validator';

    public static function convertMessages(Model $model, Context $context): array
    {
        $contents = [];
        $normalizeToolCallId = static function (string $id) use ($model): string {
            if (! self::requiresToolCallId($model->id)) {
                return $id;
            }

            return substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? $id, 0, 64);
        };

        $messages = TransformMessages::transformMessages($context->messages, $model, $normalizeToolCallId);

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $content = self::convertUserMessage($message);
                if ($content !== []) {
                    $contents[] = $content;
                }

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $content = self::convertAssistantMessage($model, $message);
                if ($content !== []) {
                    $contents[] = [
                        'role' => 'model',
                        'parts' => $content,
                    ];
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $turns = self::convertToolResult($model, $message);
                if ($turns === []) {
                    continue;
                }

                foreach ($turns as $turn) {
                    $lastIndex = array_key_last($contents);
                    $isFunctionResponseTurn = isset($turn['parts'][0]['functionResponse']);
                    if ($lastIndex !== null && ($contents[$lastIndex]['role'] ?? null) === 'user' && isset($contents[$lastIndex]['parts'])) {
                        $lastParts = $contents[$lastIndex]['parts'];
                        if ($isFunctionResponseTurn && is_array($lastParts) && array_filter($lastParts, static fn (array $part): bool => isset($part['functionResponse']))) {
                            $contents[$lastIndex]['parts'] = array_merge($contents[$lastIndex]['parts'], $turn['parts']);

                            continue;
                        }
                    }

                    $contents[] = $turn;
                }
            }
        }

        return $contents;
    }

    public static function convertTools(array $tools): array
    {
        if ($tools === []) {
            return [];
        }

        return [[
            'functionDeclarations' => array_map(static function (Tool $tool): array {
                return [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parametersJsonSchema' => $tool->parameters instanceof Schema ? $tool->parameters->toArray() : $tool->parameters,
                ];
            }, $tools),
        ]];
    }

    public static function mapToolChoice(string $choice): string
    {
        return match ($choice) {
            'none' => 'NONE',
            'any' => 'ANY',
            default => 'AUTO',
        };
    }

    public static function mapStopReason(?string $reason): StopReason
    {
        if ($reason === null) {
            return StopReason::Stop;
        }

        return match (strtoupper($reason)) {
            'STOP', 'END' => StopReason::Stop,
            'MAX_TOKENS' => StopReason::Length,
            default => StopReason::Error,
        };
    }

    public static function isGemini3ProModel(Model $model): bool
    {
        return (bool) preg_match('/gemini-3(?:\.\d+)?-pro/i', $model->id);
    }

    public static function isGemini3FlashModel(Model $model): bool
    {
        return (bool) preg_match('/gemini-3(?:\.\d+)?-flash/i', $model->id);
    }

    public static function isGemma4Model(Model $model): bool
    {
        return (bool) preg_match('/gemma-?4/i', $model->id);
    }

    public static function supportsMultimodalFunctionResponse(Model $model): bool
    {
        $major = self::getGeminiMajorVersion($model->id);

        return $major === null || $major >= 3;
    }

    public static function getThinkingLevel(ThinkingLevel $effort, Model $model): string
    {
        if (self::isGemini3ProModel($model)) {
            return match ($effort) {
                ThinkingLevel::Minimal, ThinkingLevel::Low => 'LOW',
                default => 'HIGH',
            };
        }

        if (self::isGemma4Model($model)) {
            return match ($effort) {
                ThinkingLevel::Minimal, ThinkingLevel::Low => 'MINIMAL',
                default => 'HIGH',
            };
        }

        return match ($effort) {
            ThinkingLevel::Minimal => 'MINIMAL',
            ThinkingLevel::Low => 'LOW',
            ThinkingLevel::Medium => 'MEDIUM',
            ThinkingLevel::High, ThinkingLevel::Xhigh => 'HIGH',
        };
    }

    /**
     * @param  array<string, int>  $customBudgets
     */
    public static function getGoogleBudget(Model $model, ThinkingLevel $effort, array $customBudgets = []): int
    {
        $level = $effort->value;
        if (isset($customBudgets[$level])) {
            return $customBudgets[$level];
        }

        if (str_contains($model->id, '2.5-pro')) {
            return match ($effort) {
                ThinkingLevel::Minimal => 128,
                ThinkingLevel::Low => 2048,
                ThinkingLevel::Medium => 8192,
                default => 32768,
            };
        }

        if (str_contains($model->id, '2.5-flash-lite')) {
            return match ($effort) {
                ThinkingLevel::Minimal => 512,
                ThinkingLevel::Low => 2048,
                ThinkingLevel::Medium => 8192,
                default => 24576,
            };
        }

        if (str_contains($model->id, '2.5-flash')) {
            return match ($effort) {
                ThinkingLevel::Minimal => 128,
                ThinkingLevel::Low => 2048,
                ThinkingLevel::Medium => 8192,
                default => 24576,
            };
        }

        return -1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function convertUserMessage(UserMessage $message): array
    {
        if (is_string($message->content)) {
            return [
                'role' => 'user',
                'parts' => [[
                    'text' => SanitizeUnicode::sanitizeSurrogates($message->content),
                ]],
            ];
        }

        $parts = [];
        foreach ($message->content as $item) {
            if ($item instanceof TextContent) {
                $parts[] = ['text' => SanitizeUnicode::sanitizeSurrogates($item->text)];
            } elseif ($item instanceof ImageContent) {
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $item->mimeType,
                        'data' => $item->data,
                    ],
                ];
            }
        }

        return $parts === [] ? [] : ['role' => 'user', 'parts' => $parts];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function convertAssistantMessage(Model $model, AssistantMessage $message): array
    {
        $parts = [];
        $sameModel = $message->provider->equals($model->provider) && $message->api->equals($model->api) && $message->model === $model->id;

        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                if (trim($block->text) === '') {
                    continue;
                }

                $part = [
                    'text' => SanitizeUnicode::sanitizeSurrogates($block->text),
                ];
                $signature = self::resolveThoughtSignature($sameModel, $block->textSignature);
                if ($signature !== null) {
                    $part['thoughtSignature'] = $signature;
                }

                $parts[] = $part;

                continue;
            }

            if ($block instanceof ThinkingContent) {
                if (trim($block->thinking) === '') {
                    continue;
                }

                if ($sameModel) {
                    $part = [
                        'thought' => true,
                        'text' => SanitizeUnicode::sanitizeSurrogates($block->thinking),
                    ];
                    $signature = self::resolveThoughtSignature($sameModel, $block->thinkingSignature);
                    if ($signature !== null) {
                        $part['thoughtSignature'] = $signature;
                    }

                    $parts[] = $part;
                } else {
                    $parts[] = [
                        'text' => SanitizeUnicode::sanitizeSurrogates($block->thinking),
                    ];
                }

                continue;
            }

            if ($block instanceof ToolCall) {
                $part = [
                    'functionCall' => [
                        'name' => $block->name,
                        'args' => $block->arguments,
                    ],
                ];

                if (self::requiresToolCallId($model->id)) {
                    $part['functionCall']['id'] = $block->id;
                }

                $signature = self::resolveThoughtSignature($sameModel, $block->thoughtSignature);
                if ($signature !== null) {
                    $part['thoughtSignature'] = $signature;
                } elseif (self::isGemini3Model($model)) {
                    $part['thoughtSignature'] = self::SKIP_THOUGHT_SIGNATURE;
                }

                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function convertToolResult(Model $model, ToolResultMessage $message): array
    {
        $textContent = array_values(array_filter($message->content, static fn ($item): bool => $item instanceof TextContent));
        $imageContent = $model->input !== [] && in_array('image', $model->input, true)
            ? array_values(array_filter($message->content, static fn ($item): bool => $item instanceof ImageContent))
            : [];

        $textResult = implode("\n", array_map(static fn (TextContent $item): string => $item->text, $textContent));
        $hasImages = $imageContent !== [];
        $supportsMultimodal = self::supportsMultimodalFunctionResponse($model);

        $responseValue = $textResult !== '' ? SanitizeUnicode::sanitizeSurrogates($textResult) : ($hasImages ? '(see attached image)' : '');
        $imageParts = array_map(static fn (ImageContent $image): array => [
            'inlineData' => [
                'mimeType' => $image->mimeType,
                'data' => $image->data,
            ],
        ], $imageContent);

        $functionResponse = [
            'functionResponse' => [
                'name' => $message->toolName,
                'response' => $message->isError ? ['error' => $responseValue] : ['output' => $responseValue],
            ],
        ];

        if ($hasImages && $supportsMultimodal) {
            $functionResponse['functionResponse']['parts'] = $imageParts;
        }

        if (self::requiresToolCallId($model->id)) {
            $functionResponse['functionResponse']['id'] = $message->toolCallId;
        }

        $turns = [[
            'role' => 'user',
            'parts' => [$functionResponse],
        ]];
        if ($hasImages && ! $supportsMultimodal) {
            $turns[] = [
                'role' => 'user',
                'parts' => array_merge([
                    ['text' => 'Tool result image:'],
                ], $imageParts),
            ];
        }

        return $turns;
    }

    private static function isGemini3Model(Model $model): bool
    {
        return self::isGemini3ProModel($model) || self::isGemini3FlashModel($model) || self::isGemma4Model($model);
    }

    private static function requiresToolCallId(string $modelId): bool
    {
        return str_starts_with($modelId, 'claude-') || str_starts_with($modelId, 'gpt-oss-');
    }

    private static function resolveThoughtSignature(bool $sameModel, ?string $signature): ?string
    {
        if (! $sameModel || $signature === null || $signature === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $signature) === 1 && strlen($signature) % 4 === 0
            ? $signature
            : null;
    }

    private static function getGeminiMajorVersion(string $modelId): ?int
    {
        if (preg_match('/^gemini(?:-live)?-(\d+)/i', $modelId, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
