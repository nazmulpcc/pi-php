<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\AI\ApiRegistry;
use Pi\AI\Models;

final class ExtensionRunner
{
    private readonly ExtensionRuntime $runtime;

    /** @var array<ExtensionDiagnostic> */
    private array $diagnostics = [];

    private readonly string $providerSourceId;

    public function __construct(
        /** @var array<Extension> */
        private readonly array $extensions,
        private readonly string $cwd,
    ) {
        $this->runtime = new ExtensionRuntime;
        foreach ($this->extensions as $extension) {
            $extension->api?->rebindRuntime($this->runtime);
        }
        $this->providerSourceId = 'extension_runner_'.bin2hex(random_bytes(6));
        $this->runtime->bindActions([
            'ui' => fn (): ExtensionUI => new HeadlessExtensionUI,
            'cwd' => fn (): string => $this->cwd,
            'sessionManager' => fn (): mixed => null,
            'getModel' => fn (): mixed => null,
            'isIdle' => fn (): bool => true,
            'abort' => fn (): mixed => null,
            'hasPendingMessages' => fn (): bool => false,
            'shutdown' => fn (): mixed => null,
            'getContextUsage' => fn (): array => [],
            'compact' => fn (array $options = []): array => ['changed' => false, 'summary' => ''],
            'getSystemPrompt' => fn (): string => '',
            'waitForIdle' => fn (): mixed => null,
            'newSession' => fn (array $options = []): array => ['unsupported' => true],
            'fork' => fn (string $entryId, array $options = []): array => ['unsupported' => true],
            'switchSession' => fn (string $sessionPath, array $options = []): array => ['unsupported' => true],
            'reload' => fn (): mixed => null,
            'sendMessage' => fn (array $message, array $options = []): mixed => null,
            'sendUserMessage' => fn (string|array $content, array $options = []): mixed => null,
            'appendEntry' => fn (string $customType, mixed $data = null): mixed => null,
            'setSessionName' => fn (string $name): mixed => null,
            'getSessionName' => fn (): ?string => null,
            'setLabel' => fn (string $entryId, ?string $label): mixed => null,
            'getActiveTools' => fn (): array => [],
            'getAllTools' => fn (): array => [],
            'setActiveTools' => fn (array $toolNames): mixed => null,
            'setModel' => fn (mixed $model): mixed => null,
            'getThinkingLevel' => fn (): mixed => null,
            'setThinkingLevel' => fn (mixed $level): mixed => null,
        ]);
        $this->registerProviders();
    }

    public function __destruct()
    {
        $this->dispose();
    }

    public function dispose(): void
    {
        ApiRegistry::unregisterProviders($this->providerSourceId);
        Models::unregisterDynamicModels($this->providerSourceId);
    }

    /**
     * @return array<ExtensionFlag>
     */
    public function getFlags(): array
    {
        $flags = [];
        foreach ($this->extensions as $extension) {
            foreach ($extension->flags as $name => $flag) {
                $flags[$name] ??= $flag;
            }
        }

        return array_values($flags);
    }

    /**
     * @return array<ExtensionCommand>
     */
    public function getCommands(): array
    {
        $commands = [];
        $counts = [];
        foreach ($this->extensions as $extension) {
            foreach ($extension->commands as $command) {
                $counts[$command->name] = ($counts[$command->name] ?? 0) + 1;
                $commands[] = $command;
            }
        }

        $seen = [];
        $resolved = [];
        foreach ($commands as $command) {
            $occurrence = ($seen[$command->name] ?? 0) + 1;
            $seen[$command->name] = $occurrence;
            $resolvedName = ($counts[$command->name] ?? 0) > 1 ? $command->name.':'.$occurrence : $command->name;
            $resolved[] = new ExtensionCommand($resolvedName, $command->description, $command->handler);
        }

        return $resolved;
    }

    /**
     * @return array<ExtensionTool>
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->extensions as $extension) {
            foreach ($extension->tools as $name => $tool) {
                $tools[$name] ??= $tool;
            }
        }

        return array_values($tools);
    }

    /**
     * @return array<ExtensionDiagnostic>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param  array<string, bool|string>  $flagValues
     * @param  array<string, mixed>  $actions
     */
    public function bindRuntime(array $actions, ExtensionUI $ui, array $flagValues = []): void
    {
        $actions['ui'] = fn (): ExtensionUI => $ui;
        $this->runtime->bindActions($actions);
        $this->runtime->setFlagValues($flagValues);
    }

    public function invalidate(): void
    {
        $this->runtime->invalidate();
    }

    /**
     * @return array{skillPaths:list<string>,promptPaths:list<string>,themePaths:list<string>}
     */
    public function discoverResources(): array
    {
        $context = $this->createContext(false);
        $skillPaths = [];
        $promptPaths = [];
        $themePaths = [];

        foreach ($this->extensions as $extension) {
            foreach ($extension->handlers['resources_discover'] ?? [] as $handler) {
                try {
                    $result = $handler([
                        'type' => 'resources_discover',
                        'cwd' => $this->cwd,
                        'reason' => 'startup',
                    ], $context);
                    if (is_array($result)) {
                        $skillPaths = [...$skillPaths, ...array_values(array_filter($result['skillPaths'] ?? [], 'is_string'))];
                        $promptPaths = [...$promptPaths, ...array_values(array_filter($result['promptPaths'] ?? [], 'is_string'))];
                        $themePaths = [...$themePaths, ...array_values(array_filter($result['themePaths'] ?? [], 'is_string'))];
                    }
                } catch (\Throwable $error) {
                    $this->diagnostics[] = new ExtensionDiagnostic($extension->path, $error->getMessage());
                }
            }
        }

        return [
            'skillPaths' => array_values(array_unique($skillPaths)),
            'promptPaths' => array_values(array_unique($promptPaths)),
            'themePaths' => array_values(array_unique($themePaths)),
        ];
    }

    public function emit(string $eventType, array $event, bool $commandCapable = false): mixed
    {
        $context = $this->createContext($commandCapable);
        $result = null;

        foreach ($this->extensions as $extension) {
            foreach ($extension->handlers[$eventType] ?? [] as $handler) {
                try {
                    $handlerResult = $handler($event, $context);
                    if ($handlerResult !== null) {
                        $result = $handlerResult;
                        if (is_array($result) && (($result['cancel'] ?? false) === true || ($result['block'] ?? false) === true)) {
                            return $result;
                        }
                    }
                } catch (\Throwable $error) {
                    $this->diagnostics[] = new ExtensionDiagnostic($extension->path, $error->getMessage());
                }
            }
        }

        return $result;
    }

    public function emitToolCall(array $event): ?array
    {
        $result = $this->emit('tool_call', $event);

        return is_array($result) ? $result : null;
    }

    public function emitToolResult(array $event): ?array
    {
        $result = $this->emit('tool_result', $event);

        return is_array($result) ? $result : null;
    }

    public function executeCommand(string $name, string $arguments = '', bool $commandCapable = true): mixed
    {
        foreach ($this->getCommands() as $command) {
            if ($command->name === $name) {
                $context = $this->createContext($commandCapable);

                return ($command->handler)($arguments, $context);
            }
        }

        throw new \RuntimeException(sprintf('Extension command not found: %s', $name));
    }

    public function setFlagValues(array $flagValues): void
    {
        $this->runtime->setFlagValues($flagValues);
    }

    private function registerProviders(): void
    {
        $models = [];
        foreach ($this->extensions as $extension) {
            foreach ($extension->providers as $provider) {
                if ($provider->provider !== null) {
                    ApiRegistry::registerProvider($provider->provider, $this->providerSourceId);
                } elseif ($provider->factory !== null) {
                    ApiRegistry::registerProviderFactory($provider->name, $provider->factory, $this->providerSourceId);
                }
                $models = [...$models, ...$provider->models];
            }
        }

        if ($models !== []) {
            Models::registerDynamicModels($this->providerSourceId, $models);
        }
    }

    private function createContext(bool $commandCapable): ExtensionContext
    {
        $version = $this->runtime->getVersion();
        $assertActive = function () use ($version): void {
            if ($this->runtime->getVersion() !== $version) {
                throw new \RuntimeException('This extension context is stale after session replacement or reload.');
            }
        };

        $uiAction = $this->runtime->action('ui');
        $ui = is_callable($uiAction) ? $uiAction() : $uiAction;
        $base = new ExtensionContext(
            ui: $ui,
            hasUi: ! $ui instanceof HeadlessExtensionUI,
            cwd: (string) ($this->runtime->action('cwd'))(),
            sessionManager: ($this->runtime->action('sessionManager'))(),
            modelRegistry: null,
            getModel: $this->runtime->action('getModel'),
            isIdle: $this->runtime->action('isIdle'),
            abort: $this->runtime->action('abort'),
            hasPendingMessages: $this->runtime->action('hasPendingMessages'),
            shutdown: $this->runtime->action('shutdown'),
            getContextUsage: $this->runtime->action('getContextUsage'),
            compact: $this->runtime->action('compact'),
            getSystemPrompt: $this->runtime->action('getSystemPrompt'),
            assertActive: $assertActive,
        );

        if (! $commandCapable) {
            return $base;
        }

        return new ExtensionCommandContext(
            ui: $base->ui,
            hasUi: $base->hasUi,
            cwd: $base->cwd,
            sessionManager: $base->sessionManager,
            modelRegistry: null,
            getModel: $this->runtime->action('getModel'),
            isIdle: $this->runtime->action('isIdle'),
            abort: $this->runtime->action('abort'),
            hasPendingMessages: $this->runtime->action('hasPendingMessages'),
            shutdown: $this->runtime->action('shutdown'),
            getContextUsage: $this->runtime->action('getContextUsage'),
            compact: $this->runtime->action('compact'),
            getSystemPrompt: $this->runtime->action('getSystemPrompt'),
            assertActive: $assertActive,
            waitForIdle: $this->runtime->action('waitForIdle'),
            newSession: $this->runtime->action('newSession'),
            fork: $this->runtime->action('fork'),
            switchSession: $this->runtime->action('switchSession'),
            reload: $this->runtime->action('reload'),
        );
    }
}
