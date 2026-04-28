<?php

declare(strict_types=1);

namespace Pi\Console;

use Pi\Agent\Content\TextContent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\ThinkingLevel;
use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntime;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Event\CodingAgentEvent;
use Pi\CodingAgent\Extension\Extension;
use Pi\CodingAgent\Extension\ExtensionFlag;
use Pi\CodingAgent\Extension\ExtensionRunner;
use Pi\CodingAgent\Extension\HeadlessExtensionUI;
use Pi\CodingAgent\PromptOptions;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\CodingAgent\Session\InMemorySessionStore;
use Pi\CodingAgent\Settings\SettingsManager;
use Pi\CodingAgent\Support\PromiseBlocker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Pi\AI\fauxAssistantMessage;
use function Pi\AI\registerFauxProvider;

final class MainCommand extends Command
{
    /**
     * @param  array<Extension>  $extensions
     */
    public function __construct(
        private readonly array $extensions = [],
    ) {
        parent::__construct('_default');
    }

    protected function configure(): void
    {
        $this
            ->setName('_default')
            ->setDescription('Headless Pi console for text, json, rpc, and REPL modes.')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Execution mode: text, json, or rpc')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider name')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model identifier')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key override')
            ->addOption('system-prompt', null, InputOption::VALUE_REQUIRED, 'System prompt override')
            ->addOption('append-system-prompt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Append text to the system prompt')
            ->addOption('thinking', null, InputOption::VALUE_REQUIRED, 'Thinking level')
            ->addOption('continue', 'c', InputOption::VALUE_NONE, 'Continue the latest session')
            ->addOption('resume', null, InputOption::VALUE_OPTIONAL, 'Resume a session by path or id prefix')
            ->addOption('session', null, InputOption::VALUE_REQUIRED, 'Session id or path')
            ->addOption('no-session', null, InputOption::VALUE_NONE, 'Disable persistent sessions')
            ->addOption('session-dir', null, InputOption::VALUE_REQUIRED, 'Session directory override')
            ->addOption('tools', 't', InputOption::VALUE_REQUIRED, 'Comma-separated tool allowlist')
            ->addOption('no-tools', null, InputOption::VALUE_NONE, 'Disable all tools')
            ->addOption('no-builtin-tools', null, InputOption::VALUE_NONE, 'Disable built-in tools')
            ->addOption('no-context-files', null, InputOption::VALUE_NONE, 'Disable AGENTS/context file loading')
            ->addOption('cwd', null, InputOption::VALUE_REQUIRED, 'Working directory override')
            ->addArgument('messages', InputArgument::IS_ARRAY, 'Prompt text and @file arguments');

        foreach ($this->getExtensionFlags() as $flag) {
            $this->addExtensionFlagOption($flag);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $parsed = $this->parseInput($input);

        $runtime = $this->createRuntime($parsed);
        $recentEvents = [];
        $runtime->subscribe(function (CodingAgentEvent $event) use (&$recentEvents): void {
            $recentEvents[] = $event;
            if (count($recentEvents) > 100) {
                array_shift($recentEvents);
            }
        });

        try {
            if ($parsed->mode === 'json') {
                return $this->runJsonMode($runtime, $parsed, $this->readPipedStdin());
            }

            if ($parsed->mode === 'rpc') {
                return $this->runRpcMode($runtime);
            }

            if ($parsed->mode !== null && $parsed->mode !== 'text') {
                throw new \RuntimeException(sprintf('Unsupported mode: %s', $parsed->mode));
            }

            if ($this->shouldStartRepl($parsed)) {
                return $this->runRepl($runtime, $io);
            }

            return $this->runTextMode($runtime, $parsed, $this->readPipedStdin());
        } catch (\Throwable $error) {
            $logPath = (new RuntimeFailureLogger)->log($runtime, $error, $recentEvents);
            fwrite(STDERR, $error->getMessage()."\n");
            fwrite(STDERR, sprintf("Failure details logged to %s\n", $logPath));

            return 1;
        }
    }

    private function parseInput(InputInterface $input): ParsedInput
    {
        $cwd = $input->getOption('cwd');
        $cwd = is_string($cwd) && $cwd !== '' ? $cwd : (getcwd() ?: '.');

        $messageArgs = array_map('strval', $input->getArgument('messages'));
        $messages = [];
        $fileArgs = [];
        foreach ($messageArgs as $arg) {
            if (str_starts_with($arg, '@')) {
                $fileArgs[] = substr($arg, 1);
            } else {
                $messages[] = $arg;
            }
        }

        $processor = new FileArgumentProcessor;
        $processedFiles = $processor->process($fileArgs, $cwd);

        $thinking = $input->getOption('thinking');
        $thinkingLevel = null;
        if (is_string($thinking) && $thinking !== '') {
            $thinkingLevel = ThinkingLevel::from($thinking);
        }

        $resume = $input->getOption('resume');
        $sessionTarget = $input->getOption('session');
        if ($resume !== false && is_string($sessionTarget) && $sessionTarget !== '' && $resume !== null && $resume !== $sessionTarget) {
            throw new \RuntimeException('Use either --resume or --session, not both.');
        }

        $tools = $input->getOption('tools');
        $allowedToolNames = null;
        if ($input->getOption('no-tools') || $input->getOption('no-builtin-tools')) {
            $allowedToolNames = [];
        } elseif (is_string($tools) && trim($tools) !== '') {
            $allowedToolNames = array_values(array_filter(array_map('trim', explode(',', $tools))));
        }

        $mode = $input->getOption('mode');
        $mode = is_string($mode) && $mode !== '' ? $mode : null;

        $extensionFlagValues = [];
        foreach ($this->getExtensionFlags() as $flag) {
            $value = $input->getOption($flag->name);
            if ($flag->type === 'boolean') {
                $extensionFlagValues[$flag->name] = (bool) $value;

                continue;
            }
            if (is_string($value) && $value !== '') {
                $extensionFlagValues[$flag->name] = $value;
            } elseif ($flag->default !== null) {
                $extensionFlagValues[$flag->name] = $flag->default;
            }
        }

        return new ParsedInput(
            mode: $mode,
            provider: $this->normalizeStringOption($input->getOption('provider')),
            modelId: $this->normalizeStringOption($input->getOption('model')),
            apiKey: $this->normalizeStringOption($input->getOption('api-key')),
            systemPrompt: $this->normalizeStringOption($input->getOption('system-prompt')),
            appendSystemPrompt: array_values(array_filter(array_map('strval', $input->getOption('append-system-prompt')))),
            thinkingLevel: $thinkingLevel,
            continueLatest: (bool) $input->getOption('continue'),
            resume: $resume,
            sessionTarget: is_string($sessionTarget) && $sessionTarget !== '' ? $sessionTarget : null,
            noSession: (bool) $input->getOption('no-session'),
            sessionDir: $this->normalizeStringOption($input->getOption('session-dir')),
            allowedToolNames: $allowedToolNames,
            enableContextFiles: ! (bool) $input->getOption('no-context-files'),
            cwd: $cwd,
            messages: $messages,
            fileArgs: $fileArgs,
            fileText: $processedFiles['text'],
            fileImages: $processedFiles['images'],
            extensionFlagValues: $extensionFlagValues,
        );
    }

    private function createRuntime(ParsedInput $parsed): CodingAgentRuntime
    {
        return $this->createRuntimeFromCwd(
            $parsed->cwd ?? (getcwd() ?: '.'),
            $parsed,
        );
    }

    public function createRuntimeFromCwd(string $cwd, ?ParsedInput $parsed = null): CodingAgentRuntime
    {
        $parsed ??= new ParsedInput(
            mode: null,
            provider: null,
            modelId: null,
            apiKey: null,
            systemPrompt: null,
            appendSystemPrompt: [],
            thinkingLevel: null,
            continueLatest: false,
            resume: false,
            sessionTarget: null,
            noSession: false,
            sessionDir: null,
            allowedToolNames: null,
            enableContextFiles: true,
            cwd: $cwd,
            messages: [],
            fileArgs: [],
            fileText: '',
            fileImages: [],
            extensionFlagValues: [],
        );

        $settingsManager = SettingsManager::create($cwd);
        $authStorage = AuthStorage::create();
        $sessionStore = $parsed->noSession
            ? new InMemorySessionStore
            : new FilesystemSessionStore($parsed->sessionDir ?? $settingsManager->getSessionDir($cwd));

        $config = new CodingAgentConfig(
            provider: $parsed->provider,
            modelId: $parsed->modelId,
            apiKey: $parsed->apiKey,
            cwd: $cwd,
            systemPrompt: $parsed->systemPrompt,
            thinkingLevel: $parsed->thinkingLevel,
            allowedToolNames: $parsed->allowedToolNames,
            sessionStore: $sessionStore,
            authStorage: $authStorage,
            settingsManager: $settingsManager,
            enableContextFiles: $parsed->enableContextFiles,
            appendSystemPrompt: $parsed->appendSystemPrompt,
            extensions: $this->extensions,
            extensionFlagValues: $parsed->extensionFlagValues,
            extensionUi: new HeadlessExtensionUI(
                onNotify: static function (string $message, string $type): void {
                    fwrite(STDERR, sprintf("[%s] %s\n", $type, $message));
                },
            ),
        );

        $factory = new CodingAgentRuntimeFactory;

        if ($parsed->provider === 'faux' || getenv('PI_CODING_AGENT_FAUX_RESPONSE') !== false) {
            $config = $this->withFauxProvider($config);
        }

        $sessionTarget = $parsed->sessionTarget;
        if ($sessionTarget !== null) {
            return $factory->resume($config, $sessionTarget);
        }

        if ($parsed->resume !== false) {
            if (is_string($parsed->resume) && $parsed->resume !== '') {
                return $factory->resume($config, $parsed->resume);
            }

            return $factory->continueLatest($config);
        }

        if ($parsed->continueLatest) {
            return $factory->continueLatest($config);
        }

        return $factory->create($config);
    }

    /**
     * @return array<ExtensionConsoleCommand>
     */
    public function getExtensionCommands(): array
    {
        $runner = new ExtensionRunner($this->extensions, getcwd() ?: '.');
        $flags = $runner->getFlags();
        $commands = [];
        foreach ($runner->getCommands() as $command) {
            $commands[] = new ExtensionConsoleCommand($command->name, $command->description, $this->extensions, $flags);
        }
        $runner->dispose();

        return $commands;
    }

    private function runTextMode(CodingAgentRuntime $runtime, ParsedInput $parsed, ?string $stdin): int
    {
        $message = $this->buildInitialMessage($parsed, $stdin);
        if ($message === null && ! $parsed->continueLatest && $parsed->resume === false && $parsed->sessionTarget === null) {
            throw new \RuntimeException('No prompt provided');
        }

        if ($message !== null) {
            PromiseBlocker::block($runtime->prompt($message, new PromptOptions($parsed->fileImages)));
        } else {
            PromiseBlocker::block($runtime->continue());
        }

        return $this->renderFinalAssistantMessage($runtime);
    }

    private function runJsonMode(CodingAgentRuntime $runtime, ParsedInput $parsed, ?string $stdin): int
    {
        $header = $runtime->session->sessionManager->getHeader();
        if ($header !== null) {
            fwrite(STDOUT, json_encode($header, JSON_THROW_ON_ERROR)."\n");
        }

        $runtime->subscribe(function (CodingAgentEvent $event): void {
            fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n");
        });

        $message = $this->buildInitialMessage($parsed, $stdin);
        if ($message !== null) {
            PromiseBlocker::block($runtime->prompt($message, new PromptOptions($parsed->fileImages)));
        } else {
            PromiseBlocker::block($runtime->continue());
        }

        return 0;
    }

    private function runRpcMode(CodingAgentRuntime $runtime): int
    {
        $runtime->subscribe(function (CodingAgentEvent $event): void {
            fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n");
        });

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $id = $command['id'] ?? null;
            $commandType = (string) ($command['type'] ?? '');

            try {
                $result = match ($commandType) {
                    'prompt' => $this->rpcPrompt($runtime, $command),
                    'continue' => $this->rpcContinue($runtime),
                    'steer' => $this->rpcSteer($runtime, $command),
                    'follow_up' => $this->rpcFollowUp($runtime, $command),
                    'abort' => $this->rpcAbort($runtime),
                    'new_session' => $runtime->newSession(isset($command['parentSession']) ? (string) $command['parentSession'] : null),
                    'switch_session' => $runtime->switchSession((string) ($command['sessionPath'] ?? '')),
                    'reload' => $this->rpcReload($runtime),
                    'get_state', 'state' => $this->rpcState($runtime),
                    'set_model' => $this->rpcSetModel($runtime, $command),
                    'cycle_model' => $this->rpcCycleModel($runtime),
                    'get_available_models' => $this->rpcGetAvailableModels($runtime),
                    'set_thinking_level' => $this->rpcSetThinkingLevel($runtime, $command),
                    'cycle_thinking_level' => $this->rpcCycleThinkingLevel($runtime),
                    'set_steering_mode' => $this->rpcSetSteeringMode($runtime, $command),
                    'set_follow_up_mode' => $this->rpcSetFollowUpMode($runtime, $command),
                    'compact' => $this->rpcCompact($runtime, $command),
                    'execute_bash' => $this->rpcExecuteBash($runtime, $command),
                    'shutdown' => ['shutdown' => true],
                    default => throw new \RuntimeException('Unknown RPC command'),
                };

                fwrite(STDOUT, json_encode([
                    'id' => $id,
                    'type' => 'response',
                    'command' => $commandType,
                    'success' => true,
                    'data' => $result,
                ], JSON_THROW_ON_ERROR)."\n");

                if ($commandType === 'shutdown') {
                    return 0;
                }
            } catch (\Throwable $error) {
                fwrite(STDOUT, json_encode([
                    'id' => $id,
                    'type' => 'response',
                    'command' => $commandType,
                    'success' => false,
                    'error' => $error->getMessage(),
                ], JSON_THROW_ON_ERROR)."\n");
            }
        }

        return 0;
    }

    private function runRepl(CodingAgentRuntime $runtime, SymfonyStyle $io): int
    {
        $renderState = (object) ['printedText' => false];
        $slashCommands = new ReplSlashCommandHandler;
        $unsubscribe = $runtime->subscribe(function (CodingAgentEvent $event) use ($renderState): void {
            if ($event->type === 'message_update') {
                $raw = $event->payload['assistantMessageEvent'] ?? null;
                if (is_array($raw) && ($raw['type'] ?? null) === 'text_delta') {
                    fwrite(STDOUT, (string) ($raw['delta'] ?? ''));
                    $renderState->printedText = true;
                }
            }

            if ($event->type === 'tool_execution_start') {
                fwrite(STDERR, sprintf("[tool] %s\n", (string) ($event->payload['toolName'] ?? 'tool')));
            }
        });

        try {
            while (true) {
                fwrite(STDOUT, '> ');
                $line = fgets(STDIN);
                if ($line === false) {
                    fwrite(STDOUT, "\n");

                    return 0;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, '/')) {
                    if ($line === '/continue') {
                        $renderState->printedText = false;
                        PromiseBlocker::block($runtime->continue());
                        $this->finishReplTurn($runtime, $renderState->printedText);

                        continue;
                    }

                    $result = $slashCommands->handle($line, $runtime);
                    if ($result['handled'] ?? false) {
                        if (($result['output'] ?? null) !== null) {
                            fwrite(STDOUT, rtrim((string) $result['output'])."\n");
                        }
                        if (($result['exit'] ?? false) === true) {
                            return 0;
                        }

                        continue;
                    }
                    $runner = $runtime->getExtensionRunner();
                    if ($runner instanceof ExtensionRunner) {
                        $commandName = ltrim(strtok($line, ' ') ?: '', '/');
                        $arguments = trim(substr($line, strlen('/'.$commandName)));
                        try {
                            $result = $runner->executeCommand($commandName, $arguments, true);
                            if ($result !== null) {
                                fwrite(STDOUT, rtrim((string) $result)."\n");
                            }

                            continue;
                        } catch (\Throwable) {
                            fwrite(STDOUT, sprintf("Unknown slash command: /%s\n", $commandName));

                            continue;
                        }
                    }
                }

                if ($line === '/continue') {
                    $renderState->printedText = false;
                    PromiseBlocker::block($runtime->continue());
                    $this->finishReplTurn($runtime, $renderState->printedText);

                    continue;
                }

                $renderState->printedText = false;
                PromiseBlocker::block($runtime->prompt($line));
                $this->finishReplTurn($runtime, $renderState->printedText);
            }
        } finally {
            $unsubscribe();
        }
    }

    private function finishReplTurn(CodingAgentRuntime $runtime, bool $printedStreamingText): void
    {
        if (! $printedStreamingText) {
            $assistant = $this->getLastAssistantMessage($runtime);
            if ($assistant !== null) {
                foreach ($assistant->content as $content) {
                    if ($content instanceof TextContent) {
                        fwrite(STDOUT, $content->text);
                    }
                }
            }
        }

        fwrite(STDOUT, "\n");

        $assistant = $this->getLastAssistantMessage($runtime);
        if ($assistant !== null && in_array($assistant->stopReason->value, ['error', 'aborted'], true)) {
            fwrite(STDERR, ($assistant->errorMessage ?? ('Request '.$assistant->stopReason->value))."\n");
        }
    }

    private function renderFinalAssistantMessage(CodingAgentRuntime $runtime): int
    {
        $lastMessage = $this->getLastAssistantMessage($runtime);

        if ($lastMessage instanceof AssistantMessage) {
            if (in_array($lastMessage->stopReason->value, ['error', 'aborted'], true)) {
                fwrite(STDERR, ($lastMessage->errorMessage ?? ('Request '.$lastMessage->stopReason->value))."\n");

                return 1;
            }

            foreach ($lastMessage->content as $content) {
                if ($content instanceof TextContent) {
                    fwrite(STDOUT, $content->text."\n");
                }
            }
        }

        return 0;
    }

    private function buildInitialMessage(ParsedInput $parsed, ?string $stdin): ?string
    {
        $parts = [];
        if (is_string($stdin) && trim($stdin) !== '') {
            $parts[] = trim($stdin);
        }
        if ($parsed->fileText !== '') {
            $parts[] = $parsed->fileText;
        }
        if ($parsed->messages !== []) {
            $parts[] = implode(' ', $parsed->messages);
        }

        $message = trim(implode("\n\n", $parts));

        return $message !== '' ? $message : null;
    }

    private function readPipedStdin(): ?string
    {
        if ($this->stdinIsInteractive()) {
            return null;
        }

        $contents = stream_get_contents(STDIN);

        return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
    }

    private function stdinIsInteractive(): bool
    {
        return function_exists('stream_isatty') ? stream_isatty(STDIN) : false;
    }

    private function shouldStartRepl(ParsedInput $parsed): bool
    {
        if ($parsed->continueLatest) {
            return false;
        }

        if (! $this->stdinIsInteractive()) {
            return false;
        }

        return $this->buildInitialMessage($parsed, null) === null;
    }

    private function getLastAssistantMessage(CodingAgentRuntime $runtime): ?AssistantMessage
    {
        foreach (array_reverse($runtime->getState()->messages) as $message) {
            if ($message instanceof AssistantMessage) {
                return $message;
            }
        }

        return null;
    }

    private function normalizeStringOption(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function withFauxProvider(CodingAgentConfig $config): CodingAgentConfig
    {
        $responseText = getenv('PI_CODING_AGENT_FAUX_RESPONSE') ?: 'Faux response';
        $provider = registerFauxProvider([
            'provider' => 'faux',
            'api' => 'faux',
        ]);
        $provider->setResponses([
            fauxAssistantMessage($responseText),
        ]);
        $model = $provider->getModel();

        return new CodingAgentConfig(
            model: $model,
            provider: 'faux',
            modelId: $model?->id,
            apiKey: $config->apiKey,
            cwd: $config->cwd,
            systemPrompt: $config->systemPrompt,
            thinkingLevel: $config->thinkingLevel,
            tools: $config->tools,
            allowedToolNames: $config->allowedToolNames,
            sessionStore: $config->sessionStore,
            resourceLoader: $config->resourceLoader,
            authStorage: $config->authStorage,
            settingsManager: $config->settingsManager,
            streamFn: $config->streamFn,
            getApiKey: $config->getApiKey,
            enableContextFiles: $config->enableContextFiles,
            sessionId: $config->sessionId,
            appendSystemPrompt: $config->appendSystemPrompt,
        );
    }

    private function rpcPrompt(CodingAgentRuntime $runtime, array $command): array
    {
        $message = (string) ($command['message'] ?? '');
        if ($message === '') {
            throw new \RuntimeException('RPC prompt command requires a message');
        }

        PromiseBlocker::block($runtime->prompt($message));

        return ['state' => $runtime->getState()];
    }

    private function rpcContinue(CodingAgentRuntime $runtime): array
    {
        PromiseBlocker::block($runtime->continue());

        return ['state' => $runtime->getState()];
    }

    private function rpcSteer(CodingAgentRuntime $runtime, array $command): array
    {
        PromiseBlocker::block($runtime->steer((string) ($command['message'] ?? '')));

        return ['queued' => true];
    }

    private function rpcFollowUp(CodingAgentRuntime $runtime, array $command): array
    {
        PromiseBlocker::block($runtime->followUp((string) ($command['message'] ?? '')));

        return ['queued' => true];
    }

    private function rpcAbort(CodingAgentRuntime $runtime): array
    {
        PromiseBlocker::block($runtime->abort());

        return ['aborted' => true];
    }

    private function rpcReload(CodingAgentRuntime $runtime): array
    {
        $runtime->reload();

        return ['reloaded' => true];
    }

    private function rpcState(CodingAgentRuntime $runtime): array
    {
        $state = $runtime->getState();

        return [
            'model' => $state->model,
            'thinkingLevel' => $state->thinkingLevel->value,
            'isStreaming' => $state->isStreaming,
            'isCompacting' => $state->isCompacting,
            'steeringMode' => $state->steeringMode,
            'followUpMode' => $state->followUpMode,
            'sessionFile' => $state->sessionPath,
            'sessionId' => $state->sessionId,
            'messageCount' => count($state->messages),
            'pendingMessageCount' => count($state->pendingToolCalls),
        ];
    }

    private function rpcSetModel(CodingAgentRuntime $runtime, array $command): array
    {
        foreach ($runtime->session->getAvailableModels() as $model) {
            if ($model->provider->value === (string) ($command['provider'] ?? '') && $model->id === (string) ($command['modelId'] ?? '')) {
                $runtime->session->setModel($model);

                return ['model' => $model];
            }
        }

        throw new \RuntimeException(sprintf('Model not found: %s/%s', (string) ($command['provider'] ?? ''), (string) ($command['modelId'] ?? '')));
    }

    private function rpcCycleModel(CodingAgentRuntime $runtime): array
    {
        return ['model' => $runtime->session->cycleModel()];
    }

    private function rpcGetAvailableModels(CodingAgentRuntime $runtime): array
    {
        return ['models' => $runtime->session->getAvailableModels()];
    }

    private function rpcSetThinkingLevel(CodingAgentRuntime $runtime, array $command): array
    {
        $runtime->session->setThinkingLevel(ThinkingLevel::from((string) ($command['level'] ?? ThinkingLevel::Medium->value)));

        return ['thinkingLevel' => $runtime->getState()->thinkingLevel->value];
    }

    private function rpcCycleThinkingLevel(CodingAgentRuntime $runtime): array
    {
        return ['level' => $runtime->session->cycleThinkingLevel()->value];
    }

    private function rpcSetSteeringMode(CodingAgentRuntime $runtime, array $command): array
    {
        $runtime->session->setSteeringMode((string) ($command['mode'] ?? 'one-at-a-time'));

        return ['steeringMode' => $runtime->getState()->steeringMode];
    }

    private function rpcSetFollowUpMode(CodingAgentRuntime $runtime, array $command): array
    {
        $runtime->session->setFollowUpMode((string) ($command['mode'] ?? 'one-at-a-time'));

        return ['followUpMode' => $runtime->getState()->followUpMode];
    }

    private function rpcCompact(CodingAgentRuntime $runtime, array $command): array
    {
        return $runtime->compact((int) ($command['keepLastMessages'] ?? 8));
    }

    private function rpcExecuteBash(CodingAgentRuntime $runtime, array $command): array
    {
        return ['result' => PromiseBlocker::block($runtime->session->executeBash((string) ($command['command'] ?? '')))];
    }

    /**
     * @return array<ExtensionFlag>
     */
    private function getExtensionFlags(): array
    {
        $runner = new ExtensionRunner($this->extensions, getcwd() ?: '.');
        $flags = $runner->getFlags();
        $runner->dispose();

        return $flags;
    }

    private function addExtensionFlagOption(ExtensionFlag $flag): void
    {
        $mode = $flag->type === 'boolean' ? InputOption::VALUE_NONE : InputOption::VALUE_REQUIRED;
        $default = $flag->type === 'boolean' ? null : $flag->default;
        $this->addOption($flag->name, null, $mode, $flag->description, $default);
    }
}
