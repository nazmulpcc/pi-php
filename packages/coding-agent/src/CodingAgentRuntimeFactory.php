<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\Agent\Tool\AgentTool;
use Pi\AI\Model;
use Pi\CodingAgent\Resource\FilesystemResourceLoader;
use Pi\CodingAgent\Session\InMemorySessionStore;
use Pi\CodingAgent\Session\SessionSnapshot;
use Pi\CodingAgent\Tool\BashTool;
use Pi\CodingAgent\Tool\EditTool;
use Pi\CodingAgent\Tool\FindTool;
use Pi\CodingAgent\Tool\GrepTool;
use Pi\CodingAgent\Tool\LsTool;
use Pi\CodingAgent\Tool\ReadTool;
use Pi\CodingAgent\Tool\ToolRegistry;
use Pi\CodingAgent\Tool\WriteTool;

use function Pi\AI\getModel;

final class CodingAgentRuntimeFactory
{
    public function create(CodingAgentConfig $config): CodingAgentRuntime
    {
        $sessionStore = $config->sessionStore ?? new InMemorySessionStore;
        $resourceLoader = $config->resourceLoader ?? new FilesystemResourceLoader;
        $cwd = $config->cwd ?? getcwd() ?: '.';
        $model = $this->resolveModel($config);
        $tools = $this->resolveTools($cwd, $config->tools, $config->allowedToolNames);
        $contextFiles = $config->enableContextFiles ? $resourceLoader->loadContextFiles($cwd) : [];
        $systemPrompt = SystemPromptBuilder::build(
            $config->systemPrompt ?? 'You are a practical coding assistant for a PHP developer. Be concise, accurate, and concrete.',
            $contextFiles,
        );

        $snapshot = $sessionStore->createSnapshot(
            cwd: $cwd,
            model: $model,
            systemPrompt: $systemPrompt,
            thinkingLevel: $config->thinkingLevel,
            messages: [],
            sessionId: $config->sessionId,
        );
        $snapshot = $sessionStore->save($snapshot);

        return new CodingAgentRuntime(
            snapshot: $snapshot,
            sessionStore: $sessionStore,
            model: $model,
            thinkingLevel: $config->thinkingLevel,
            systemPrompt: $systemPrompt,
            tools: $tools,
            resourceLoader: $resourceLoader,
            explicitApiKey: $config->apiKey,
            customStreamFn: $config->streamFn,
            getApiKey: $config->getApiKey,
        );
    }

    public function resume(CodingAgentConfig $config, string $sessionIdOrPath): CodingAgentRuntime
    {
        $sessionStore = $config->sessionStore ?? new InMemorySessionStore;
        $resourceLoader = $config->resourceLoader ?? new FilesystemResourceLoader;
        $snapshot = $sessionStore->load($sessionIdOrPath);
        if (! $snapshot instanceof SessionSnapshot) {
            throw new \RuntimeException(sprintf('Session not found: %s', $sessionIdOrPath));
        }

        $cwd = $config->cwd ?? $snapshot->cwd;
        $model = $config->model ?? $snapshot->model ?? $this->resolveModel($config);
        $tools = $this->resolveTools($cwd, $config->tools, $config->allowedToolNames);

        return new CodingAgentRuntime(
            snapshot: $snapshot,
            sessionStore: $sessionStore,
            model: $model,
            thinkingLevel: $config->thinkingLevel ?? $snapshot->thinkingLevel,
            systemPrompt: $snapshot->systemPrompt,
            tools: $tools,
            resourceLoader: $resourceLoader,
            explicitApiKey: $config->apiKey,
            customStreamFn: $config->streamFn,
            getApiKey: $config->getApiKey,
        );
    }

    public function continueLatest(CodingAgentConfig $config): CodingAgentRuntime
    {
        $sessionStore = $config->sessionStore ?? new InMemorySessionStore;
        $snapshot = $sessionStore->loadLatest();
        if (! $snapshot instanceof SessionSnapshot) {
            return $this->create($config);
        }

        return $this->resume($config, $snapshot->path ?? $snapshot->sessionId);
    }

    /**
     * @param  array<AgentTool>  $customTools
     * @param  array<string>|null  $allowedToolNames
     * @return array<AgentTool>
     */
    private function resolveTools(string $cwd, array $customTools, ?array $allowedToolNames): array
    {
        $builtIns = [
            new ReadTool($cwd),
            new BashTool($cwd),
            new EditTool($cwd),
            new WriteTool($cwd),
            new FindTool($cwd),
            new GrepTool($cwd),
            new LsTool($cwd),
        ];

        return (new ToolRegistry($builtIns, $customTools))->resolve($allowedToolNames);
    }

    private function resolveModel(CodingAgentConfig $config): ?Model
    {
        if ($config->model instanceof Model) {
            return $config->model;
        }

        if ($config->provider !== null && $config->modelId !== null) {
            return getModel($config->provider, $config->modelId);
        }

        return null;
    }
}
