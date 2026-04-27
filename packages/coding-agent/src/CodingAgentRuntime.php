<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

use Pi\Agent\AgentMessage;
use Pi\Agent\ThinkingLevel;
use Pi\AI\Model;
use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\Event\CodingAgentEvent;
use Pi\CodingAgent\Resource\PromptTemplate;
use Pi\CodingAgent\Resource\ResourceLoaderInterface;
use Pi\CodingAgent\Resource\Skill;
use Pi\CodingAgent\Session\SessionManager;
use Pi\CodingAgent\Session\SessionStore;
use Pi\CodingAgent\Settings\SettingsManager;
use Pi\CodingAgent\Support\PromiseBlocker;
use React\Promise\PromiseInterface;

final class CodingAgentRuntime
{
    public CodingAgentSession $session;

    /** @var array<callable(CodingAgentEvent): void> */
    private array $listeners = [];

    private mixed $sessionSubscription = null;

    private mixed $rebindSession = null;

    private mixed $beforeSessionInvalidate = null;

    public function __construct(
        private readonly SessionStore $sessionStore,
        SessionManager $sessionManager,
        private readonly ResourceLoaderInterface $resourceLoader,
        private readonly array $tools,
        private readonly ?AuthStorage $authStorage = null,
        private readonly ?SettingsManager $settingsManager = null,
        private readonly ?string $explicitApiKey = null,
        private readonly mixed $customStreamFn = null,
        private readonly mixed $getApiKey = null,
        private string $systemPrompt = '',
        private ?Model $model = null,
        private ThinkingLevel $thinkingLevel = ThinkingLevel::Medium,
    ) {
        $this->session = $this->createSession($sessionManager, 'new', null);
    }

    public function prompt(string|AgentMessage|array $input, ?PromptOptions $options = null): PromiseInterface
    {
        return $this->session->prompt($input, $options);
    }

    public function continue(): PromiseInterface
    {
        return $this->session->continue();
    }

    public function steer(string|AgentMessage|array $input, ?array $images = null): PromiseInterface
    {
        return $this->session->steer($input, $images);
    }

    public function followUp(string|AgentMessage|array $input, ?array $images = null): PromiseInterface
    {
        return $this->session->followUp($input, $images);
    }

    public function abort(): PromiseInterface
    {
        return $this->session->abort();
    }

    public function waitForIdle(): PromiseInterface
    {
        return $this->session->waitForIdle();
    }

    public function compact(int $keepLastMessages = 8): array
    {
        return $this->session->compact($keepLastMessages);
    }

    public function getState(): CodingAgentState
    {
        return $this->session->getState();
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

    public function setRebindSession(?callable $rebindSession): void
    {
        $this->rebindSession = $rebindSession;
    }

    public function setBeforeSessionInvalidate(?callable $beforeSessionInvalidate): void
    {
        $this->beforeSessionInvalidate = $beforeSessionInvalidate;
    }

    public function newSession(?string $parentSession = null): array
    {
        if ($this->session->getState()->isStreaming) {
            PromiseBlocker::block($this->session->abort());
        }

        $previous = $this->session->getState()->sessionPath;
        $manager = $this->sessionStore->createManager($this->session->getState()->cwd);
        $this->replaceSession($manager, 'new', $previous);

        return ['cancelled' => false];
    }

    public function switchSession(string $sessionIdOrPath): array
    {
        if ($this->session->getState()->isStreaming) {
            PromiseBlocker::block($this->session->abort());
        }

        $manager = $this->sessionStore->openManager($sessionIdOrPath, $this->session->getState()->cwd);
        if (! $manager instanceof SessionManager) {
            throw new \RuntimeException(sprintf('Session not found: %s', $sessionIdOrPath));
        }

        $previous = $this->session->getState()->sessionPath;
        $this->replaceSession($manager, 'resume', $previous);

        return ['cancelled' => false];
    }

    public function reload(): void
    {
        $path = $this->session->getState()->sessionPath;
        if ($path === null) {
            return;
        }

        $manager = $this->sessionStore->openManager($path, $this->session->getState()->cwd);
        if (! $manager instanceof SessionManager) {
            throw new \RuntimeException(sprintf('Session not found: %s', $path));
        }

        $previous = $this->session->getState()->sessionPath;
        $this->replaceSession($manager, 'reload', $previous);
    }

    /**
     * @return array<Skill>
     */
    public function getSkills(): array
    {
        return $this->session->getSkills();
    }

    /**
     * @return array<PromptTemplate>
     */
    public function getPromptTemplates(): array
    {
        return $this->session->getPromptTemplates();
    }

    private function replaceSession(SessionManager $manager, string $reason, ?string $previousSessionFile): void
    {
        foreach ($this->listeners as $listener) {
            $listener(new CodingAgentEvent('session_shutdown', [
                'reason' => $reason,
                'targetSessionFile' => $manager->getSessionFile(),
            ]));
        }
        if ($this->beforeSessionInvalidate !== null) {
            ($this->beforeSessionInvalidate)();
        }
        $this->session->dispose();
        $this->session = $this->createSession($manager, $reason, $previousSessionFile);
        if ($this->rebindSession !== null) {
            ($this->rebindSession)($this->session);
        }
    }

    private function createSession(SessionManager $manager, string $reason, ?string $previousSessionFile): CodingAgentSession
    {
        $context = $manager->buildSessionContext();
        if (($context['model'] ?? null) instanceof Model) {
            $this->model = $context['model'];
        }
        if (($context['thinkingLevel'] ?? null) instanceof ThinkingLevel) {
            $this->thinkingLevel = $context['thinkingLevel'];
        }

        $session = new CodingAgentSession(
            sessionManager: $manager,
            model: $this->model,
            systemPrompt: $this->systemPrompt,
            thinkingLevel: $this->thinkingLevel,
            tools: $this->tools,
            resourceLoader: $this->resourceLoader,
            authStorage: $this->authStorage,
            settingsManager: $this->settingsManager,
            explicitApiKey: $this->explicitApiKey,
            customStreamFn: $this->customStreamFn,
            getApiKey: $this->getApiKey,
        );

        if ($this->sessionSubscription !== null) {
            ($this->sessionSubscription)();
        }
        $this->sessionSubscription = $session->subscribe(function (CodingAgentEvent $event): void {
            foreach ($this->listeners as $listener) {
                $listener($event);
            }
        });

        foreach ($this->listeners as $listener) {
            $listener(new CodingAgentEvent('session_start', [
                'reason' => $reason,
                'sessionFile' => $manager->getSessionFile(),
                'sessionId' => $manager->getSessionId(),
                'previousSessionFile' => $previousSessionFile,
            ]));
        }

        return $session;
    }
}
