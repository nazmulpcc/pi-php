<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Cli;

use Pi\Agent\Content\TextContent;
use Pi\Agent\Message\AssistantMessage;
use Pi\Agent\ThinkingLevel;
use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntime;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Event\CodingAgentEvent;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\CodingAgent\Session\InMemorySessionStore;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function Pi\AI\fauxAssistantMessage;
use function Pi\AI\registerFauxProvider;

final class Application
{
    public function run(array $argv): int
    {
        try {
            $args = $this->parseArgs($argv);
            $runtime = $this->createRuntime($args);

            return match ($args->mode) {
                'text' => $this->runTextMode($runtime, $args, $this->readPipedStdin()),
                'json' => $this->runJsonMode($runtime, $args, $this->readPipedStdin()),
                'rpc' => $this->runRpcMode($runtime),
                default => throw new \RuntimeException(sprintf('Unsupported mode: %s', $args->mode)),
            };
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage()."\n");

            return 1;
        }
    }

    public function parseArgs(array $argv): Args
    {
        $mode = 'text';
        $provider = null;
        $modelId = null;
        $apiKey = null;
        $systemPrompt = null;
        $thinkingLevel = ThinkingLevel::Medium;
        $continueLatest = false;
        $resume = null;
        $noSession = false;
        $sessionDir = null;
        $tools = null;
        $messages = [];
        $cwd = null;

        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (($arg === '--mode' || $arg === '-m') && isset($argv[$i + 1])) {
                $mode = $argv[++$i];
            } elseif ($arg === '--provider' && isset($argv[$i + 1])) {
                $provider = $argv[++$i];
            } elseif ($arg === '--model' && isset($argv[$i + 1])) {
                $modelId = $argv[++$i];
            } elseif ($arg === '--api-key' && isset($argv[$i + 1])) {
                $apiKey = $argv[++$i];
            } elseif ($arg === '--system-prompt' && isset($argv[$i + 1])) {
                $systemPrompt = $argv[++$i];
            } elseif ($arg === '--thinking' && isset($argv[$i + 1])) {
                $thinkingLevel = ThinkingLevel::from($argv[++$i]);
            } elseif ($arg === '--continue' || $arg === '-c') {
                $continueLatest = true;
            } elseif ($arg === '--resume' && isset($argv[$i + 1])) {
                $resume = $argv[++$i];
            } elseif ($arg === '--no-session') {
                $noSession = true;
            } elseif ($arg === '--session-dir' && isset($argv[$i + 1])) {
                $sessionDir = $argv[++$i];
            } elseif (($arg === '--tools' || $arg === '-t') && isset($argv[$i + 1])) {
                $tools = array_values(array_filter(array_map('trim', explode(',', $argv[++$i]))));
            } elseif ($arg === '--cwd' && isset($argv[$i + 1])) {
                $cwd = $argv[++$i];
            } elseif ($arg === '--help' || $arg === '-h') {
                $this->printHelp();
                exit(0);
            } else {
                $messages[] = $arg;
            }
        }

        return new Args(
            mode: $mode,
            provider: $provider,
            modelId: $modelId,
            apiKey: $apiKey,
            systemPrompt: $systemPrompt,
            thinkingLevel: $thinkingLevel,
            continueLatest: $continueLatest,
            resume: $resume,
            noSession: $noSession,
            sessionDir: $sessionDir,
            tools: $tools,
            messages: $messages,
            cwd: $cwd,
        );
    }

    private function createRuntime(Args $args): CodingAgentRuntime
    {
        $cwd = $args->cwd ?? getcwd() ?: '.';
        $sessionStore = $args->noSession
            ? new InMemorySessionStore
            : new FilesystemSessionStore($args->sessionDir ?? $cwd.'/.pi/sessions');

        $config = new CodingAgentConfig(
            provider: $args->provider,
            modelId: $args->modelId,
            apiKey: $args->apiKey,
            cwd: $cwd,
            systemPrompt: $args->systemPrompt,
            thinkingLevel: $args->thinkingLevel,
            allowedToolNames: $args->tools,
            sessionStore: $sessionStore,
        );

        $factory = new CodingAgentRuntimeFactory;

        if ($args->provider === 'faux' || getenv('PI_CODING_AGENT_FAUX_RESPONSE') !== false) {
            $config = $this->withFauxProvider($config);
        }

        if ($args->resume !== null) {
            return $factory->resume($config, $args->resume);
        }

        if ($args->continueLatest) {
            return $factory->continueLatest($config);
        }

        return $factory->create($config);
    }

    private function runTextMode(CodingAgentRuntime $runtime, Args $args, ?string $stdin): int
    {
        $message = $this->buildInitialMessage($args, $stdin);
        if ($message === null && ! $args->continueLatest && $args->resume === null) {
            throw new \RuntimeException('No prompt provided');
        }

        if ($message !== null) {
            \Pi\CodingAgent\Cli\block($runtime->prompt($message));
        } else {
            \Pi\CodingAgent\Cli\block($runtime->continue());
        }

        $lastMessage = array_values(array_filter(
            array_reverse($runtime->getState()->messages),
            static fn ($message): bool => $message instanceof AssistantMessage,
        ))[0] ?? null;

        if ($lastMessage instanceof AssistantMessage) {
            foreach ($lastMessage->content as $content) {
                if ($content instanceof TextContent) {
                    fwrite(STDOUT, $content->text);
                }
            }
            fwrite(STDOUT, "\n");
        }

        return 0;
    }

    private function runJsonMode(CodingAgentRuntime $runtime, Args $args, ?string $stdin): int
    {
        $runtime->subscribe(function (CodingAgentEvent $event): void {
            fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n");
        });

        $message = $this->buildInitialMessage($args, $stdin);
        if ($message !== null) {
            \Pi\CodingAgent\Cli\block($runtime->prompt($message));
        } else {
            \Pi\CodingAgent\Cli\block($runtime->continue());
        }

        return 0;
    }

    private function runRpcMode(CodingAgentRuntime $runtime): int
    {
        $runtime->subscribe(function (CodingAgentEvent $event): void {
            fwrite(STDOUT, json_encode(['type' => 'event', 'event' => $event], JSON_THROW_ON_ERROR)."\n");
        });

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $command = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $id = $command['id'] ?? null;
            try {
                $result = match ($command['type'] ?? null) {
                    'prompt' => $this->rpcPrompt($runtime, $command),
                    'continue' => $this->rpcContinue($runtime),
                    'abort' => $this->rpcAbort($runtime),
                    'state' => $runtime->getState(),
                    'compact' => ['summary' => $runtime->compact((int) ($command['keepLastMessages'] ?? 8))],
                    'shutdown' => ['shutdown' => true],
                    default => throw new \RuntimeException('Unknown RPC command'),
                };

                fwrite(STDOUT, json_encode([
                    'id' => $id,
                    'type' => 'response',
                    'success' => true,
                    'data' => $result,
                ], JSON_THROW_ON_ERROR)."\n");

                if (($command['type'] ?? null) === 'shutdown') {
                    return 0;
                }
            } catch (\Throwable $error) {
                fwrite(STDOUT, json_encode([
                    'id' => $id,
                    'type' => 'response',
                    'success' => false,
                    'error' => $error->getMessage(),
                ], JSON_THROW_ON_ERROR)."\n");
            }
        }

        return 0;
    }

    private function rpcPrompt(CodingAgentRuntime $runtime, array $command): array
    {
        $message = (string) ($command['message'] ?? '');
        if ($message === '') {
            throw new \RuntimeException('RPC prompt command requires a message');
        }

        \Pi\CodingAgent\Cli\block($runtime->prompt($message));

        return ['state' => $runtime->getState()];
    }

    private function rpcContinue(CodingAgentRuntime $runtime): array
    {
        \Pi\CodingAgent\Cli\block($runtime->continue());

        return ['state' => $runtime->getState()];
    }

    private function rpcAbort(CodingAgentRuntime $runtime): array
    {
        $runtime->abort();

        return ['aborted' => true];
    }

    private function buildInitialMessage(Args $args, ?string $stdin): ?string
    {
        $parts = [];
        if (is_string($stdin) && trim($stdin) !== '') {
            $parts[] = trim($stdin);
        }
        if ($args->messages !== []) {
            $parts[] = implode(' ', $args->messages);
        }

        $message = trim(implode("\n\n", $parts));

        return $message !== '' ? $message : null;
    }

    private function readPipedStdin(): ?string
    {
        if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
            return null;
        }

        $contents = stream_get_contents(STDIN);

        return is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
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
            streamFn: $config->streamFn,
            getApiKey: $config->getApiKey,
            enableContextFiles: $config->enableContextFiles,
            sessionId: $config->sessionId,
        );
    }

    private function printHelp(): void
    {
        fwrite(STDOUT, <<<'TEXT'
Usage: php bin/pi [options] [message...]

Options:
  --mode, -m <text|json|rpc>
  --provider <name>
  --model <id>
  --api-key <key>
  --system-prompt <text>
  --thinking <off|minimal|low|medium|high|xhigh>
  --continue, -c
  --resume <id-or-path>
  --no-session
  --session-dir <dir>
  --tools, -t <comma-separated tool names>
  --cwd <path>
  --help, -h

TEXT);
    }
}

function block(PromiseInterface $promise): mixed
{
    $value = null;
    $error = null;
    $settled = false;
    $loop = Loop::get();
    $timer = null;

    $promise->then(
        function ($resolved) use (&$value, &$settled, $loop, &$timer): void {
            $value = $resolved;
            $settled = true;
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
            $loop->stop();
        },
        function ($rejected) use (&$error, &$settled, $loop, &$timer): void {
            $error = $rejected;
            $settled = true;
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
            $loop->stop();
        },
    );

    if (! $settled) {
        $timer = Loop::addTimer(30.0, function () use (&$settled, &$error, $loop): void {
            if ($settled) {
                return;
            }

            $settled = true;
            $error = new \RuntimeException('Promise did not settle before timeout');
            $loop->stop();
        });
        $loop->run();
    }

    if ($error !== null) {
        throw $error;
    }

    return $value;
}
