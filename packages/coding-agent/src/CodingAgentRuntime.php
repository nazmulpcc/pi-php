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
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\Message\CustomMessage;
use Pi\Agent\Message\ToolResultMessage;
use Pi\Agent\Message\UserMessage;
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
use Pi\CodingAgent\Session\SessionSnapshot;
use Pi\CodingAgent\Session\SessionStore;
use React\Promise\PromiseInterface;

use function Pi\AI\getEnvApiKey;
use function Pi\AI\streamSimple;

final class CodingAgentRuntime
{
    private Agent $agent;

    /** @var array<callable(CodingAgentEvent): void> */
    private array $listeners = [];

    public function __construct(
        private SessionSnapshot $snapshot,
        private readonly SessionStore $sessionStore,
        private ?Model $model,
        private ThinkingLevel $thinkingLevel,
        private string $systemPrompt,
        private array $tools,
        private readonly ResourceLoaderInterface $resourceLoader,
        private readonly ?string $explicitApiKey = null,
        private readonly mixed $customStreamFn = null,
        private readonly mixed $getApiKey = null,
    ) {
        $this->agent = $this->createAgent();
        $this->agent->subscribe(function (AgentEvent $event): void {
            $this->persistState();
            $this->emit(CodingAgentEventSerializer::fromAgentEvent($event, $this->snapshot->sessionId));
        });
    }

    public function prompt(string|AgentMessage|array $input, ?PromptOptions $options = null): PromiseInterface
    {
        $options ??= new PromptOptions;
        $promise = is_string($input)
            ? $this->agent->prompt($input, $options->images)
            : $this->agent->prompt($input);

        return $promise->then(function () {
            $this->persistState();

            return null;
        });
    }

    public function continue(): PromiseInterface
    {
        return $this->agent->continue()->then(function () {
            $this->persistState();

            return null;
        });
    }

    public function abort(): void
    {
        $this->agent->abort();
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

    public function getState(): CodingAgentState
    {
        $state = $this->agent->getState();

        return new CodingAgentState(
            sessionId: $this->snapshot->sessionId,
            sessionPath: $this->snapshot->path,
            cwd: $this->snapshot->cwd,
            model: $this->model,
            systemPrompt: $state->getSystemPrompt(),
            thinkingLevel: $state->getThinkingLevel(),
            messages: $state->getMessages(),
            isStreaming: $state->isStreaming(),
            streamingMessage: $state->getStreamingMessage(),
            pendingToolCalls: $state->getPendingToolCalls(),
            errorMessage: $state->getErrorMessage(),
            toolNames: array_map(static fn (AgentTool $tool): string => $tool->getName(), $state->getTools()),
        );
    }

    public function compact(int $keepLastMessages = 8): string
    {
        $messages = $this->agent->getState()->getMessages();
        if (count($messages) <= $keepLastMessages) {
            $summary = 'No compaction was necessary.';
            $this->emit(new CodingAgentEvent('compaction_end', $this->snapshot->sessionId, (int) (microtime(true) * 1000), [
                'summary' => $summary,
                'changed' => false,
            ]));

            return $summary;
        }

        $keepLastMessages = max(1, $keepLastMessages);
        $olderMessages = array_slice($messages, 0, -$keepLastMessages);
        $keptMessages = array_slice($messages, -$keepLastMessages);
        $summary = $this->summarizeMessages($olderMessages);

        $summaryMessage = new UserMessage([
            new TextContent("Compacted conversation summary:\n".$summary),
        ], (int) (microtime(true) * 1000));

        $this->agent->getState()->setMessages([$summaryMessage, ...$keptMessages]);
        $this->persistState();
        $this->emit(new CodingAgentEvent('compaction_end', $this->snapshot->sessionId, (int) (microtime(true) * 1000), [
            'summary' => $summary,
            'changed' => true,
        ]));

        return $summary;
    }

    public function switchSession(string $sessionIdOrPath): void
    {
        $snapshot = $this->sessionStore->load($sessionIdOrPath);
        if (! $snapshot instanceof SessionSnapshot) {
            throw new \RuntimeException(sprintf('Session not found: %s', $sessionIdOrPath));
        }

        $this->snapshot = $snapshot;
        $this->model = $snapshot->model ?? $this->model;
        $this->thinkingLevel = $snapshot->thinkingLevel;
        $this->systemPrompt = $snapshot->systemPrompt;
        $this->agent = $this->createAgent();
        $this->agent->subscribe(function (AgentEvent $event): void {
            $this->persistState();
            $this->emit(CodingAgentEventSerializer::fromAgentEvent($event, $this->snapshot->sessionId));
        });
        $this->emit(new CodingAgentEvent('session_switched', $this->snapshot->sessionId, (int) (microtime(true) * 1000), [
            'sessionId' => $this->snapshot->sessionId,
            'sessionPath' => $this->snapshot->path,
        ]));
    }

    /**
     * @return array<Skill>
     */
    public function getSkills(): array
    {
        return $this->resourceLoader->loadSkills($this->snapshot->cwd);
    }

    /**
     * @return array<PromptTemplate>
     */
    public function getPromptTemplates(): array
    {
        return $this->resourceLoader->loadPromptTemplates($this->snapshot->cwd);
    }

    public function waitForIdle(): PromiseInterface
    {
        return $this->agent->waitForIdle();
    }

    private function createAgent(): Agent
    {
        $state = new MutableAgentState(
            systemPrompt: $this->systemPrompt,
            thinkingLevel: $this->thinkingLevel,
            tools: $this->tools,
            messages: $this->snapshot->messages,
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

                $apiKey = $this->resolveApiKey();

                return streamSimple(
                    $this->model,
                    AiAdapter::toAiContext($context),
                    new SimpleStreamOptions(
                        apiKey: $apiKey,
                        signal: AiAdapter::toAiCancellation($signal),
                        reasoning: $this->toAiThinkingLevel($this->thinkingLevel),
                        sessionId: $this->snapshot->sessionId,
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

    private function summarizeMessages(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = $message->getRole()->value;
            $text = match (true) {
                $message instanceof UserMessage => $this->flattenContent($message->content),
                $message instanceof AssistantMessage => $this->flattenContent($message->content),
                $message instanceof ToolResultMessage => sprintf('%s => %s', $message->toolName, $this->flattenContent($message->content)),
                $message instanceof CustomMessage => $this->flattenContent($message->content),
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

    private function persistState(): void
    {
        $state = $this->agent->getState();
        $this->snapshot = $this->sessionStore->save(new SessionSnapshot(
            sessionId: $this->snapshot->sessionId,
            cwd: $this->snapshot->cwd,
            model: $this->model,
            systemPrompt: $state->getSystemPrompt(),
            thinkingLevel: $state->getThinkingLevel(),
            messages: $state->getMessages(),
            createdAt: $this->snapshot->createdAt,
            updatedAt: (int) (microtime(true) * 1000),
            path: $this->snapshot->path,
        ));
    }

    private function emit(CodingAgentEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }
}
