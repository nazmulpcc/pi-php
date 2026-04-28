<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

use Pi\Agent\ToolExecutionMode;
use Pi\AI\ApiProviderInterface;
use Pi\AI\Model;
use Pi\AI\Schema\Schema;

final class ExtensionAPI
{
    public function __construct(
        private readonly Extension $extension,
        private ExtensionRuntime $runtime,
    ) {}

    public function rebindRuntime(ExtensionRuntime $runtime): void
    {
        $this->runtime = $runtime;
    }

    public function on(string $event, callable $handler): void
    {
        $this->extension->handlers[$event] ??= [];
        $this->extension->handlers[$event][] = $handler;
    }

    public function registerTool(
        string $name,
        string $label,
        string $description,
        array|Schema $parameters,
        callable $execute,
        ToolExecutionMode $executionMode = ToolExecutionMode::Sequential,
        ?callable $prepareArguments = null,
    ): void {
        if (! isset($this->extension->tools[$name])) {
            $this->extension->tools[$name] = new ExtensionTool(
                name: $name,
                label: $label,
                description: $description,
                parameters: $parameters,
                execute: $execute(...),
                executionMode: $executionMode,
                prepareArguments: $prepareArguments === null ? null : $prepareArguments(...),
            );
        }
    }

    public function registerCommand(string $name, string $description, callable $handler): void
    {
        $this->extension->commands[$name] = new ExtensionCommand($name, $description, $handler(...));
    }

    public function registerFlag(string $name, string $description, string $type = 'boolean', bool|string|null $default = null): void
    {
        if (! isset($this->extension->flags[$name])) {
            $this->extension->flags[$name] = new ExtensionFlag($name, $description, $type, $default);
        }

        if ($default !== null && $this->runtime->getFlagValue($name) === null) {
            $this->runtime->setFlagValue($name, $default);
        }
    }

    /**
     * @param  array<Model>  $models
     */
    public function registerProvider(string $name, ?ApiProviderInterface $provider = null, ?\Closure $factory = null, array $models = []): void
    {
        $this->extension->providers[$name] = new ExtensionProvider($name, $provider, $factory, $models);
    }

    public function sendMessage(array $message, array $options = []): mixed
    {
        return ($this->runtime->action('sendMessage'))($message, $options);
    }

    public function sendUserMessage(string|array $content, array $options = []): mixed
    {
        return ($this->runtime->action('sendUserMessage'))($content, $options);
    }

    public function appendEntry(string $customType, mixed $data = null): mixed
    {
        return ($this->runtime->action('appendEntry'))($customType, $data);
    }

    public function setSessionName(string $name): void
    {
        ($this->runtime->action('setSessionName'))($name);
    }

    public function getSessionName(): ?string
    {
        return ($this->runtime->action('getSessionName'))();
    }

    public function setLabel(string $entryId, ?string $label): void
    {
        ($this->runtime->action('setLabel'))($entryId, $label);
    }

    public function getActiveTools(): array
    {
        return ($this->runtime->action('getActiveTools'))();
    }

    public function getAllTools(): array
    {
        return ($this->runtime->action('getAllTools'))();
    }

    public function setActiveTools(array $toolNames): void
    {
        ($this->runtime->action('setActiveTools'))($toolNames);
    }

    public function setModel(Model $model): mixed
    {
        return ($this->runtime->action('setModel'))($model);
    }

    public function getThinkingLevel(): mixed
    {
        return ($this->runtime->action('getThinkingLevel'))();
    }

    public function setThinkingLevel(mixed $level): void
    {
        ($this->runtime->action('setThinkingLevel'))($level);
    }

    public function getFlag(string $name): bool|string|null
    {
        if (! isset($this->extension->flags[$name])) {
            return null;
        }

        return $this->runtime->getFlagValue($name);
    }
}
