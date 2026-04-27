<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ThinkingContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\CustomMessage;
use Pi\Agent\Message\ToolResultMessage;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\StopReason;
use Pi\Agent\ThinkingLevel;
use Pi\AI\Api;
use Pi\AI\Model;
use Pi\AI\Provider;
use Pi\AI\UsageCost;
use Pi\CodingAgent\Event\CodingAgentEventSerializer;

final class MessageSerializer
{
    public static function serializeSnapshot(SessionSnapshot $snapshot): array
    {
        return [
            'sessionId' => $snapshot->sessionId,
            'cwd' => $snapshot->cwd,
            'model' => self::serializeModel($snapshot->model),
            'systemPrompt' => $snapshot->systemPrompt,
            'thinkingLevel' => $snapshot->thinkingLevel->value,
            'messages' => array_map([self::class, 'serializeMessage'], $snapshot->messages),
            'createdAt' => $snapshot->createdAt,
            'updatedAt' => $snapshot->updatedAt,
        ];
    }

    public static function hydrateSnapshot(array $data, ?string $path = null): SessionSnapshot
    {
        return new SessionSnapshot(
            sessionId: (string) $data['sessionId'],
            cwd: (string) $data['cwd'],
            model: self::hydrateModel($data['model'] ?? null),
            systemPrompt: (string) ($data['systemPrompt'] ?? ''),
            thinkingLevel: ThinkingLevel::from((string) ($data['thinkingLevel'] ?? ThinkingLevel::Medium->value)),
            messages: array_map([self::class, 'hydrateMessage'], $data['messages'] ?? []),
            createdAt: (int) ($data['createdAt'] ?? (int) (microtime(true) * 1000)),
            updatedAt: (int) ($data['updatedAt'] ?? (int) (microtime(true) * 1000)),
            path: $path,
        );
    }

    public static function serializeMessage(AgentMessage $message): array
    {
        return CodingAgentEventSerializer::serializeMessage($message);
    }

    public static function hydrateMessage(array $data): AgentMessage
    {
        return match ($data['role'] ?? null) {
            'user' => new UserMessage(
                content: array_map([self::class, 'hydrateContent'], $data['content'] ?? []),
                timestamp: (int) ($data['timestamp'] ?? 0),
            ),
            'assistant' => new AssistantMessage(
                content: array_map([self::class, 'hydrateContent'], $data['content'] ?? []),
                api: (string) ($data['api'] ?? 'unknown'),
                provider: (string) ($data['provider'] ?? 'unknown'),
                model: (string) ($data['model'] ?? 'unknown'),
                stopReason: StopReason::from((string) ($data['stopReason'] ?? StopReason::Done->value)),
                timestamp: (int) ($data['timestamp'] ?? 0),
                errorMessage: isset($data['errorMessage']) ? (string) $data['errorMessage'] : null,
            ),
            'tool_result' => new ToolResultMessage(
                toolCallId: (string) ($data['toolCallId'] ?? ''),
                toolName: (string) ($data['toolName'] ?? ''),
                content: array_map([self::class, 'hydrateContent'], $data['content'] ?? []),
                timestamp: (int) ($data['timestamp'] ?? 0),
                isError: (bool) ($data['isError'] ?? false),
                details: $data['details'] ?? null,
            ),
            'custom' => new CustomMessage(
                customType: (string) ($data['customType'] ?? 'custom'),
                content: array_map([self::class, 'hydrateContent'], $data['content'] ?? []),
                timestamp: (int) ($data['timestamp'] ?? 0),
                display: (bool) ($data['display'] ?? true),
                details: $data['details'] ?? null,
            ),
            default => throw new \RuntimeException('Unsupported message role'),
        };
    }

    public static function hydrateContent(array $data): object
    {
        return match ($data['type'] ?? null) {
            'text' => new TextContent((string) ($data['text'] ?? '')),
            'image' => new ImageContent((string) ($data['data'] ?? ''), (string) ($data['mimeType'] ?? 'application/octet-stream')),
            'thinking' => new ThinkingContent(
                (string) ($data['thinking'] ?? ''),
                isset($data['thinkingSignature']) ? (string) $data['thinkingSignature'] : null,
                (bool) ($data['redacted'] ?? false),
            ),
            'tool_call' => new ToolCall(
                (string) ($data['id'] ?? ''),
                (string) ($data['name'] ?? ''),
                $data['arguments'] ?? [],
                isset($data['thoughtSignature']) ? (string) $data['thoughtSignature'] : null,
            ),
            default => throw new \RuntimeException('Unsupported content type'),
        };
    }

    public static function serializeModel(?Model $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'id' => $model->id,
            'name' => $model->name,
            'api' => $model->api->value,
            'provider' => $model->provider->value,
            'baseUrl' => $model->baseUrl,
            'reasoning' => $model->reasoning,
            'input' => $model->input,
            'cost' => [
                'input' => $model->cost->input,
                'output' => $model->cost->output,
                'cacheRead' => $model->cost->cacheRead,
                'cacheWrite' => $model->cost->cacheWrite,
            ],
            'contextWindow' => $model->contextWindow,
            'maxTokens' => $model->maxTokens,
            'headers' => $model->headers,
            'compat' => $model->compat,
        ];
    }

    public static function hydrateModel(?array $data): ?Model
    {
        if ($data === null) {
            return null;
        }

        return new Model(
            id: (string) $data['id'],
            name: (string) $data['name'],
            api: new Api((string) $data['api']),
            provider: new Provider((string) $data['provider']),
            baseUrl: (string) $data['baseUrl'],
            reasoning: (bool) $data['reasoning'],
            input: $data['input'] ?? ['text'],
            cost: new UsageCost(
                input: (float) ($data['cost']['input'] ?? 0.0),
                output: (float) ($data['cost']['output'] ?? 0.0),
                cacheRead: (float) ($data['cost']['cacheRead'] ?? 0.0),
                cacheWrite: (float) ($data['cost']['cacheWrite'] ?? 0.0),
            ),
            contextWindow: (int) $data['contextWindow'],
            maxTokens: (int) $data['maxTokens'],
            headers: $data['headers'] ?? [],
            compat: $data['compat'] ?? null,
        );
    }
}
