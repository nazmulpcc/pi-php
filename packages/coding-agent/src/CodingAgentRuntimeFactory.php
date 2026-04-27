<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\Agent\Tool\AgentTool;
use Pi\AI\Model;
use Pi\CodingAgent\Resource\FilesystemResourceLoader;
use Pi\CodingAgent\Resource\ResourceLoaderInterface;
use Pi\CodingAgent\Session\InMemorySessionStore;
use Pi\CodingAgent\Session\SessionManager;
use Pi\CodingAgent\Session\SessionStore;
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

        $manager = $sessionStore->createManager($cwd, $config->sessionId);
        if ($model instanceof Model) {
            $manager->appendModelChange($model);
        }
        $manager->appendThinkingLevelChange($config->thinkingLevel);

        return $this->createRuntime($sessionStore, $resourceLoader, $tools, $config, $systemPrompt, $model, $manager);
    }

    public function resume(CodingAgentConfig $config, string $sessionIdOrPath): CodingAgentRuntime
    {
        $sessionStore = $config->sessionStore ?? new InMemorySessionStore;
        $resourceLoader = $config->resourceLoader ?? new FilesystemResourceLoader;
        $cwd = $config->cwd ?? getcwd() ?: '.';
        $manager = $sessionStore->openManager($sessionIdOrPath, $cwd);
        if (! $manager instanceof SessionManager) {
            throw new \RuntimeException(sprintf('Session not found: %s', $sessionIdOrPath));
        }

        $runtimeContext = $manager->buildSessionContext();
        $runtimeCwd = $config->cwd ?? $manager->getCwd();
        $tools = $this->resolveTools($runtimeCwd, $config->tools, $config->allowedToolNames);
        $contextFiles = $config->enableContextFiles ? $resourceLoader->loadContextFiles($runtimeCwd) : [];
        $systemPrompt = SystemPromptBuilder::build(
            $config->systemPrompt ?? 'You are a practical coding assistant for a PHP developer. Be concise, accurate, and concrete.',
            $contextFiles,
        );
        $model = $config->model ?? $runtimeContext['model'] ?? $this->resolveModel($config);
        $thinkingLevel = $runtimeContext['thinkingLevel'] ?? $config->thinkingLevel;

        $config = new CodingAgentConfig(
            model: $model,
            provider: $config->provider,
            modelId: $config->modelId,
            apiKey: $config->apiKey,
            cwd: $runtimeCwd,
            systemPrompt: $config->systemPrompt,
            thinkingLevel: $thinkingLevel,
            tools: $config->tools,
            allowedToolNames: $config->allowedToolNames,
            sessionStore: $sessionStore,
            resourceLoader: $resourceLoader,
            streamFn: $config->streamFn,
            getApiKey: $config->getApiKey,
            enableContextFiles: $config->enableContextFiles,
            sessionId: $config->sessionId,
        );

        return $this->createRuntime($sessionStore, $resourceLoader, $tools, $config, $systemPrompt, $model, $manager);
    }

    public function continueLatest(CodingAgentConfig $config): CodingAgentRuntime
    {
        $sessionStore = $config->sessionStore ?? new InMemorySessionStore;
        $cwd = $config->cwd ?? getcwd() ?: '.';
        $manager = $sessionStore->continueLatest($cwd);
        if (! $manager instanceof SessionManager) {
            return $this->create($config);
        }

        return $this->resume($config, $manager->getSessionFile() ?? $manager->getSessionId());
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

    /**
     * @param  array<AgentTool>  $tools
     */
    private function createRuntime(
        SessionStore $sessionStore,
        ResourceLoaderInterface $resourceLoader,
        array $tools,
        CodingAgentConfig $config,
        string $systemPrompt,
        ?Model $model,
        SessionManager $manager,
    ): CodingAgentRuntime {
        return new CodingAgentRuntime(
            sessionStore: $sessionStore,
            resourceLoader: $resourceLoader,
            tools: $tools,
            explicitApiKey: $config->apiKey,
            customStreamFn: $config->streamFn,
            getApiKey: $config->getApiKey,
            systemPrompt: $systemPrompt,
            model: $model,
            thinkingLevel: $config->thinkingLevel,
            sessionManager: $manager,
        );
    }
}
