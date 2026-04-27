<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\Agent\Agent;
use Pi\Agent\AgentContext;
use Pi\Agent\AgentMessage;
use Pi\Agent\AiAdapter;
use Pi\Agent\CancellationToken;
use Pi\Agent\Content\ImageContent;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Content\ThinkingContent;
use Pi\Agent\Content\ToolCall;
use Pi\Agent\Event\AgentEvent;
use Pi\Agent\Event\MessageEndEvent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\ToolResultMessage;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\MessageRole;
use Pi\Agent\MutableAgentState;
use Pi\Agent\ThinkingLevel;
use Pi\Agent\Tool\AgentTool;
use Pi\AI\Model;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\ThinkingLevel as AiThinkingLevel;
use Pi\CodingAgent\Event\CodingAgentEvent;
use Pi\CodingAgent\Event\CodingAgentEventSerializer;
use Pi\CodingAgent\Resource\PromptTemplate;
use Pi\CodingAgent\Resource\ResourceLoaderInterface;
use Pi\CodingAgent\Resource\Skill;
use Pi\CodingAgent\Session\SessionManager;
use React\Promise\PromiseInterface;

use function Pi\AI\getEnvApiKey;
use function Pi\AI\getModels;
use function Pi\AI\getProviders;
use function Pi\AI\streamSimple;
use function React\Promise\resolve;

final class CodingAgentSession
{
    public readonly Agent $agent;

    /** @var array<callable(CodingAgentEvent): void> */
    private array $listeners = [];

    private bool $disposed = false;

    private bool $isCompacting = false;

    private mixed $unsubscribeAgent = null;

    public function __construct(
        public readonly SessionManager $sessionManager,
        private ?Model $model,
        private string $systemPrompt,
        private ThinkingLevel $thinkingLevel,
        private array $tools,
        private readonly ResourceLoaderInterface $resourceLoader,
        private readonly ?string $explicitApiKey = null,
        private readonly mixed $customStreamFn = null,
        private readonly mixed $getApiKey = null,
    ) {
        $this->agent = $this->createAgent();
        $this->unsubscribeAgent = $this->agent->subscribe(function (AgentEvent $event): void {
            $this->handleAgentEvent($event);
        });
    }

    public function prompt(string|AgentMessage|array $input, ?PromptOptions $options = null): PromiseInterface
    {
        $this->assertUsable();
        $options ??= new PromptOptions;
        $messages = $this->normalizePromptInput($input, $options->images);

        foreach ($messages as $message) {
            $this->sessionManager->appendMessage($message);
        }

        return $this->agent->prompt($messages);
    }

    public function continue(): PromiseInterface
    {
        $this->assertUsable();

        return $this->agent->continue();
    }

    public function steer(string|AgentMessage|array $input, ?array $images = null): PromiseInterface
    {
        $this->assertUsable();
        $messages = $this->normalizePromptInput($input, $images);
        foreach ($messages as $message) {
            $this->agent->steer($message);
        }

        return resolve(null);
    }

    public function followUp(string|AgentMessage|array $input, ?array $images = null): PromiseInterface
    {
        $this->assertUsable();
        $messages = $this->normalizePromptInput($input, $images);
        foreach ($messages as $message) {
            $this->agent->followUp($message);
        }

        return resolve(null);
    }

    public function abort(): PromiseInterface
    {
        $this->assertUsable();
        $this->agent->abort();

        return $this->agent->waitForIdle();
    }

    public function waitForIdle(): PromiseInterface
    {
        return $this->agent->waitForIdle();
    }

    public function reload(): void
    {
        $this->assertUsable();
        $this->sessionManager->reload();
        $context = $this->sessionManager->buildSessionContext();
        $this->model = $context['model'] ?? $this->model;
        $this->thinkingLevel = $context['thinkingLevel'] ?? $this->thinkingLevel;
        $this->agent->getState()->setMessages($context['messages']);
        $this->agent->getState()->setThinkingLevel($this->thinkingLevel);
    }

    public function subscribe(callable $listener): callable
    {
        $this->listeners[] = $listener;

        return function () use ($listener): void {
            $this->listeners = array_values(array_filter(
                $this->listeners,
                static fn (callable $registered): bool => $registered !== $listener,
            ));
        };
    }

    public function dispose(string $reason = 'This session handle is stale and can no longer be used.'): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        if ($this->unsubscribeAgent !== null) {
            ($this->unsubscribeAgent)();
            $this->unsubscribeAgent = null;
        }
        $this->listeners = [];
    }

    public function compact(int $keepLastMessages = 8): array
    {
        $this->assertUsable();
        $messages = $this->agent->getState()->getMessages();
        if (count($messages) <= $keepLastMessages) {
            $event = new CodingAgentEvent('compaction_end', [
                'reason' => 'manual',
                'aborted' => false,
                'result' => ['summary' => 'No compaction was necessary.', 'changed' => false],
            ]);
            $this->emit($event);

            return ['summary' => 'No compaction was necessary.', 'changed' => false];
        }

        $this->isCompacting = true;
        $this->emit(new CodingAgentEvent('compaction_start', ['reason' => 'manual']));

        $keepLastMessages = max(1, $keepLastMessages);
        $olderMessages = array_slice($messages, 0, -$keepLastMessages);
        $keptMessages = array_slice($messages, -$keepLastMessages);
        $summary = $this->summarizeMessages($olderMessages);
        $firstKeptEntryId = $this->resolveFirstKeptEntryId(count($keptMessages));
        $this->sessionManager->appendCompaction($summary, $firstKeptEntryId, count($messages));
        $this->sessionManager->reload();
        $context = $this->sessionManager->buildSessionContext();
        $this->agent->getState()->setMessages($context['messages']);
        $this->isCompacting = false;

        $result = ['summary' => $summary, 'changed' => true];
        $this->emit(new CodingAgentEvent('compaction_end', [
            'reason' => 'manual',
            'aborted' => false,
            'result' => $result,
        ]));

        return $result;
    }

    public function getState(): CodingAgentState
    {
        $state = $this->agent->getState();

        return new CodingAgentState(
            sessionId: $this->sessionManager->getSessionId(),
            sessionPath: $this->sessionManager->getSessionFile(),
            cwd: $this->sessionManager->getCwd(),
            model: $this->model,
            systemPrompt: $state->getSystemPrompt(),
            thinkingLevel: $state->getThinkingLevel(),
            messages: $state->getMessages(),
            isStreaming: $state->isStreaming(),
            streamingMessage: $state->getStreamingMessage(),
            pendingToolCalls: $state->getPendingToolCalls(),
            errorMessage: $state->getErrorMessage(),
            toolNames: array_map(static fn (AgentTool $tool): string => $tool->getName(), $state->getTools()),
            isCompacting: $this->isCompacting,
            steeringMode: $this->agent->getSteeringMode(),
            followUpMode: $this->agent->getFollowUpMode(),
        );
    }

    /**
     * @return array<Skill>
     */
    public function getSkills(): array
    {
        return $this->resourceLoader->loadSkills($this->sessionManager->getCwd());
    }

    /**
     * @return array<PromptTemplate>
     */
    public function getPromptTemplates(): array
    {
        return $this->resourceLoader->loadPromptTemplates($this->sessionManager->getCwd());
    }

    /**
     * @return array<Model>
     */
    public function getAvailableModels(): array
    {
        $models = [];
        foreach (getProviders() as $provider) {
            foreach (getModels($provider) as $model) {
                $models[] = $model;
            }
        }

        return $models;
    }

    public function setModel(Model $model): void
    {
        $this->assertUsable();
        $this->model = $model;
        $this->sessionManager->appendModelChange($model);
    }

    public function cycleModel(): ?Model
    {
        $models = $this->getAvailableModels();
        if ($models === []) {
            return null;
        }

        $current = $this->model;
        if ($current === null) {
            $this->setModel($models[0]);

            return $models[0];
        }

        foreach ($models as $index => $model) {
            if ($model->provider->value === $current->provider->value && $model->id === $current->id) {
                $next = $models[($index + 1) % count($models)];
                $this->setModel($next);

                return $next;
            }
        }

        $this->setModel($models[0]);

        return $models[0];
    }

    public function setThinkingLevel(ThinkingLevel $thinkingLevel): void
    {
        $this->assertUsable();
        $this->thinkingLevel = $thinkingLevel;
        $this->agent->getState()->setThinkingLevel($thinkingLevel);
        $this->sessionManager->appendThinkingLevelChange($thinkingLevel);
    }

    public function cycleThinkingLevel(): ThinkingLevel
    {
        $levels = array_values(ThinkingLevel::cases());
        $current = $this->agent->getState()->getThinkingLevel();
        $index = array_search($current, $levels, true);
        $next = $levels[$index === false ? 0 : (($index + 1) % count($levels))];
        $this->setThinkingLevel($next);

        return $next;
    }

    public function setSteeringMode(string $mode): void
    {
        $this->assertUsable();
        $this->agent->setSteeringMode($mode);
    }

    public function setFollowUpMode(string $mode): void
    {
        $this->assertUsable();
        $this->agent->setFollowUpMode($mode);
    }

    public function executeBash(string $command): PromiseInterface
    {
        $this->assertUsable();
        foreach ($this->tools as $tool) {
            if ($tool->getName() !== 'bash') {
                continue;
            }

            return resolve($tool->execute('bash_manual_'.bin2hex(random_bytes(4)), ['command' => $command]));
        }

        throw new \RuntimeException('Bash tool is not available');
    }

    private function createAgent(): Agent
    {
        $context = $this->sessionManager->buildSessionContext();
        if (($context['model'] ?? null) instanceof Model && $this->model === null) {
            $this->model = $context['model'];
        }
        if (($context['thinkingLevel'] ?? null) instanceof ThinkingLevel) {
            $this->thinkingLevel = $context['thinkingLevel'];
        }

        $state = new MutableAgentState(
            systemPrompt: $this->systemPrompt,
            thinkingLevel: $this->thinkingLevel,
            tools: $this->tools,
            messages: $context['messages'] ?? [],
        );

        return new Agent(
            initialState: $state,
            streamFn: function ($ignoredModel, AgentContext $context, $config, ?CancellationToken $signal) {
                if ($this->customStreamFn !== null) {
                    return ($this->customStreamFn)($this->model, $context, $config, $signal);
                }

                if (! $this->model instanceof Model) {
                    throw new \RuntimeException('No model configured for coding agent runtime');
                }

                return streamSimple(
                    $this->model,
                    AiAdapter::toAiContext($context),
                    new SimpleStreamOptions(
                        apiKey: $this->resolveApiKey(),
                        signal: AiAdapter::toAiCancellation($signal),
                        reasoning: $this->toAiThinkingLevel($this->thinkingLevel),
                        sessionId: $this->sessionManager->getSessionId(),
                    ),
                );
            },
            getApiKey: function (string $provider): ?string {
                if ($this->getApiKey !== null) {
                    return ($this->getApiKey)($provider);
                }

                if ($this->explicitApiKey !== null && $this->model?->provider->value === $provider) {
                    return $this->explicitApiKey;
                }

                return getEnvApiKey($provider);
            },
        );
    }

    private function handleAgentEvent(AgentEvent $event): void
    {
        if ($event instanceof MessageEndEvent) {
            if ($event->message->getRole() !== MessageRole::User) {
                $this->sessionManager->appendMessage($event->message);
            }
        }

        $this->emit(CodingAgentEventSerializer::fromAgentEvent($event));
    }

    private function emit(CodingAgentEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }

    private function resolveApiKey(): ?string
    {
        if ($this->model === null) {
            return null;
        }

        if ($this->explicitApiKey !== null) {
            return $this->explicitApiKey;
        }

        if ($this->getApiKey !== null) {
            return ($this->getApiKey)($this->model->provider->value);
        }

        return getEnvApiKey($this->model->provider->value);
    }

    private function toAiThinkingLevel(ThinkingLevel $thinkingLevel): ?AiThinkingLevel
    {
        return match ($thinkingLevel) {
            ThinkingLevel::Off => null,
            ThinkingLevel::Minimal => AiThinkingLevel::Minimal,
            ThinkingLevel::Low => AiThinkingLevel::Low,
            ThinkingLevel::Medium => AiThinkingLevel::Medium,
            ThinkingLevel::High => AiThinkingLevel::High,
            ThinkingLevel::Xhigh => AiThinkingLevel::Xhigh,
        };
    }

    /**
     * @return array<AgentMessage>
     */
    private function normalizePromptInput(string|AgentMessage|array $input, ?array $images): array
    {
        if (is_array($input)) {
            return $input;
        }

        if (! is_string($input)) {
            return [$input];
        }

        $content = [new TextContent($input)];
        if ($images !== null && $images !== []) {
            $content = array_merge($content, $images);
        }

        return [new UserMessage($content, (int) (microtime(true) * 1000))];
    }

    private function summarizeMessages(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = $message->getRole()->value;
            $text = match (true) {
                $message instanceof UserMessage => $this->flattenContent($message->content),
                $message instanceof AssistantMessage => $this->flattenContent($message->content),
                $message instanceof ToolResultMessage => sprintf('%s => %s', $message->toolName, $this->flattenContent($message->content)),
                default => '',
            };
            $lines[] = sprintf('- %s: %s', $role, mb_substr(trim($text), 0, 200));
        }

        return implode("\n", $lines);
    }

    private function flattenContent(array $content): string
    {
        $parts = [];
        foreach ($content as $item) {
            if ($item instanceof TextContent) {
                $parts[] = $item->text;
            } elseif ($item instanceof ThinkingContent) {
                $parts[] = $item->thinking;
            } elseif ($item instanceof ToolCall) {
                $parts[] = sprintf('[tool:%s %s]', $item->name, json_encode($item->arguments, JSON_THROW_ON_ERROR));
            } elseif ($item instanceof ImageContent) {
                $parts[] = '[image]';
            }
        }

        return trim(implode(' ', $parts));
    }

    private function resolveFirstKeptEntryId(int $keptMessageCount): string
    {
        $messageEntries = array_values(array_filter(
            $this->sessionManager->getEntries(),
            static fn (array $entry): bool => ($entry['type'] ?? null) === 'message',
        ));

        if ($messageEntries === []) {
            return '';
        }

        $index = max(0, count($messageEntries) - $keptMessageCount);

        return (string) ($messageEntries[$index]['id'] ?? $messageEntries[0]['id']);
    }

    private function assertUsable(): void
    {
        if ($this->disposed) {
            throw new \RuntimeException('This session handle is stale and can no longer be used.');
        }
    }
}
