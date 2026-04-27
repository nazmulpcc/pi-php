<?php

declare(strict_types=1);

namespace Pi\AI;

use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Event\DoneEvent;
use Pi\AI\Event\ErrorEvent;
use Pi\AI\Event\StartEvent;
use Pi\AI\Event\TextDeltaEvent;
use Pi\AI\Event\TextEndEvent;
use Pi\AI\Event\TextStartEvent;
use Pi\AI\Event\ThinkingDeltaEvent;
use Pi\AI\Event\ThinkingEndEvent;
use Pi\AI\Event\ThinkingStartEvent;
use Pi\AI\Event\ToolCallDeltaEvent;
use Pi\AI\Event\ToolCallEndEvent;
use Pi\AI\Event\ToolCallStartEvent;
use Pi\AI\Message\AssistantMessage;
use Pi\AI\Message\Message;
use Pi\AI\Message\UserMessage;
use Pi\AI\Provider as ProviderName;
use Pi\AI\Support\JsonParse;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class Faux
{
    public static function text(string $text): TextContent
    {
        return new TextContent($text);
    }

    public static function thinking(string $thinking): ThinkingContent
    {
        return new ThinkingContent($thinking);
    }

    public static function toolCall(string $name, array $arguments, array $options = []): ToolCall
    {
        return new ToolCall(
            id: $options['id'] ?? self::randomId('tool'),
            name: $name,
            arguments: $arguments,
        );
    }

    public static function assistantMessage(string|TextContent|ThinkingContent|ToolCall|array $content, array $options = []): AssistantMessage
    {
        $blocks = self::normalizeAssistantContent($content);

        return new AssistantMessage(
            content: $blocks,
            api: new Api('faux'),
            provider: new ProviderName('faux'),
            model: 'faux-1',
            usage: Usage::zero(),
            stopReason: $options['stopReason'] ?? StopReason::Stop,
            timestamp: $options['timestamp'] ?? time(),
            responseId: $options['responseId'] ?? null,
            errorMessage: $options['errorMessage'] ?? null,
        );
    }

    public static function registerProvider(array $options = []): FauxProviderRegistration
    {
        $api = $options['api'] ?? self::randomId('faux');
        $provider = $options['provider'] ?? 'faux';
        $sourceId = self::randomId('faux-provider');
        $tokenMin = $options['tokenSize']['min'] ?? 3;
        $tokenMax = $options['tokenSize']['max'] ?? 5;

        $modelDefinitions = $options['models'] ?? [[
            'id' => 'faux-1',
            'name' => 'Faux Model',
            'reasoning' => false,
            'input' => ['text', 'image'],
            'cost' => ['input' => 0.0, 'output' => 0.0, 'cacheRead' => 0.0, 'cacheWrite' => 0.0],
            'contextWindow' => 128000,
            'maxTokens' => 16384,
        ]];

        $models = array_map(
            static fn (array $definition): Model => new Model(
                id: $definition['id'],
                name: $definition['name'] ?? $definition['id'],
                api: new Api($api),
                provider: new ProviderName($provider),
                baseUrl: 'http://localhost:0',
                reasoning: $definition['reasoning'] ?? false,
                input: $definition['input'] ?? ['text', 'image'],
                cost: new UsageCost(
                    input: (float) ($definition['cost']['input'] ?? 0.0),
                    output: (float) ($definition['cost']['output'] ?? 0.0),
                    cacheRead: (float) ($definition['cost']['cacheRead'] ?? 0.0),
                    cacheWrite: (float) ($definition['cost']['cacheWrite'] ?? 0.0),
                ),
                contextWindow: $definition['contextWindow'] ?? 128000,
                maxTokens: $definition['maxTokens'] ?? 16384,
            ),
            $modelDefinitions,
        );

        $registration = new FauxProviderRegistration(
            api: $api,
            provider: $provider,
            sourceId: $sourceId,
            models: $models,
            tokensPerSecond: $options['tokensPerSecond'] ?? null,
            tokenMin: min($tokenMin, $tokenMax),
            tokenMax: max($tokenMin, $tokenMax),
        );

        ApiRegistry::registerProvider(new FauxApiProvider($registration), $sourceId);

        return $registration;
    }

    /**
     * @param  string|TextContent|ThinkingContent|ToolCall|array<TextContent|ThinkingContent|ToolCall>  $content
     * @return array<TextContent|ThinkingContent|ToolCall>
     */
    private static function normalizeAssistantContent(string|TextContent|ThinkingContent|ToolCall|array $content): array
    {
        if (is_string($content)) {
            return [self::text($content)];
        }

        return is_array($content) ? $content : [$content];
    }

    private static function randomId(string $prefix): string
    {
        return sprintf('%s:%s', $prefix, uniqid('', true));
    }
}

final class FauxProviderRegistration
{
    /** @var array<Model> */
    public array $models;

    /** @var array{callCount: int} */
    public array $state = ['callCount' => 0];

    /** @var array<int, AssistantMessage|callable(Context, ?StreamOptions, array{callCount:int}, Model): AssistantMessage|PromiseInterface<AssistantMessage>> */
    private array $pendingResponses = [];

    /** @var array<string, string> */
    private array $promptCache = [];

    /**
     * @param  array<Model>  $models
     */
    public function __construct(
        public string $api,
        public string $provider,
        public string $sourceId,
        array $models,
        public ?int $tokensPerSecond,
        public int $tokenMin,
        public int $tokenMax,
    ) {
        $this->models = $models;
    }

    public function getModel(?string $modelId = null): ?Model
    {
        if ($modelId === null) {
            return $this->models[0] ?? null;
        }

        foreach ($this->models as $model) {
            if ($model->id === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @param  array<int, AssistantMessage|callable(Context, ?StreamOptions, array{callCount:int}, Model): AssistantMessage|PromiseInterface<AssistantMessage>>  $responses
     */
    public function setResponses(array $responses): void
    {
        $this->pendingResponses = array_values($responses);
    }

    /**
     * @param  array<int, AssistantMessage|callable(Context, ?StreamOptions, array{callCount:int}, Model): AssistantMessage|PromiseInterface<AssistantMessage>>  $responses
     */
    public function appendResponses(array $responses): void
    {
        array_push($this->pendingResponses, ...$responses);
    }

    public function getPendingResponseCount(): int
    {
        return count($this->pendingResponses);
    }

    public function unregister(): void
    {
        ApiRegistry::unregisterProviders($this->sourceId);
    }

    public function nextResponseStep(): AssistantMessage|callable|null
    {
        $step = array_shift($this->pendingResponses);

        return $step instanceof AssistantMessage || is_callable($step) ? $step : null;
    }

    public function storePrompt(string $sessionId, string $prompt): void
    {
        $this->promptCache[$sessionId] = $prompt;
    }

    public function getPrompt(string $sessionId): ?string
    {
        return $this->promptCache[$sessionId] ?? null;
    }
}

final readonly class FauxApiProvider implements ApiProviderInterface
{
    public function __construct(
        private FauxProviderRegistration $registration,
    ) {}

    public function getApi(): Api
    {
        return new Api($this->registration->api);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $step = $this->registration->nextResponseStep();
        $this->registration->state['callCount']++;

        resolve($step instanceof AssistantMessage || is_callable($step)
            ? $this->resolveStep($step, $context, $options, $model)
            : $this->createErrorMessage('No more faux responses queued', $model)
        )->then(function (AssistantMessage $message) use ($stream, $context, $options, $model): void {
            $prepared = $this->withUsageEstimate($this->cloneMessage($message, $model), $context, $options);
            $this->emitMessage($stream, $prepared, $options);
        }, function (mixed $error) use ($stream, $model): void {
            $this->emitMessage($stream, $this->createErrorMessage($error instanceof \Throwable ? $error->getMessage() : (string) $error, $model), null);
        });

        return $stream;
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        return $this->stream($model, $context, $options);
    }

    private function resolveStep(AssistantMessage|callable $step, Context $context, ?StreamOptions $options, Model $model): AssistantMessage|PromiseInterface
    {
        if ($step instanceof AssistantMessage) {
            return $step;
        }

        return $step($context, $options, $this->registration->state, $model);
    }

    private function cloneMessage(AssistantMessage $message, Model $model): AssistantMessage
    {
        return new AssistantMessage(
            content: $message->content,
            api: $model->api,
            provider: $model->provider,
            model: $model->id,
            usage: $message->usage,
            stopReason: $message->stopReason,
            timestamp: $message->timestamp,
            responseId: $message->responseId,
            errorMessage: $message->errorMessage,
        );
    }

    private function createErrorMessage(string $error, Model $model): AssistantMessage
    {
        return new AssistantMessage(
            content: [],
            api: $model->api,
            provider: $model->provider,
            model: $model->id,
            usage: Usage::zero(),
            stopReason: StopReason::Error,
            timestamp: time(),
            errorMessage: $error,
        );
    }

    private function withUsageEstimate(AssistantMessage $message, Context $context, ?StreamOptions $options): AssistantMessage
    {
        $promptText = $this->serializeContext($context);
        $promptTokens = $this->estimateTokens($promptText);
        $outputTokens = $this->estimateTokens($this->assistantContentToText($message->content));
        $input = $promptTokens;
        $cacheRead = 0;
        $cacheWrite = 0;

        $sessionId = $options?->sessionId;
        if ($sessionId !== null && $options?->cacheRetention !== CacheRetention::None) {
            $previousPrompt = $this->registration->getPrompt($sessionId);
            if ($previousPrompt !== null) {
                $cachedChars = $this->commonPrefixLength($previousPrompt, $promptText);
                $cacheRead = $this->estimateTokens(substr($previousPrompt, 0, $cachedChars));
                $cacheWrite = $this->estimateTokens(substr($promptText, $cachedChars));
                $input = max(0, $promptTokens - $cacheRead);
            } else {
                $cacheWrite = $promptTokens;
            }

            $this->registration->storePrompt($sessionId, $promptText);
        }

        return new AssistantMessage(
            content: $message->content,
            api: $message->api,
            provider: $message->provider,
            model: $message->model,
            usage: new Usage(
                input: $input,
                output: $outputTokens,
                cacheRead: $cacheRead,
                cacheWrite: $cacheWrite,
                totalTokens: $input + $outputTokens + $cacheRead + $cacheWrite,
                cost: new UsageCost,
            ),
            stopReason: $message->stopReason,
            timestamp: $message->timestamp,
            responseId: $message->responseId,
            errorMessage: $message->errorMessage,
        );
    }

    private function emitMessage(AssistantMessageEventStream $stream, AssistantMessage $message, ?StreamOptions $options): void
    {
        if ($options?->signal?->isCancelled()) {
            $aborted = new AssistantMessage(
                content: [],
                api: $message->api,
                provider: $message->provider,
                model: $message->model,
                usage: Usage::zero(),
                stopReason: StopReason::Aborted,
                timestamp: time(),
                errorMessage: 'Request was aborted',
            );
            $stream->push(new ErrorEvent(StopReason::Aborted, $aborted));

            return;
        }

        $partialContent = [];
        $stream->push(new StartEvent($this->snapshotAssistant($message, $partialContent)));

        foreach ($message->content as $index => $block) {
            if ($block instanceof ThinkingContent) {
                $partialContent[] = new ThinkingContent('');
                $stream->push(new ThinkingStartEvent($index, $this->snapshotAssistant($message, $partialContent)));
                foreach ($this->splitString($block->thinking) as $chunk) {
                    /** @var ThinkingContent $existing */
                    $existing = $partialContent[$index];
                    $partialContent[$index] = new ThinkingContent($existing->thinking.$chunk);
                    $stream->push(new ThinkingDeltaEvent($index, $chunk, $this->snapshotAssistant($message, $partialContent)));
                }
                $stream->push(new ThinkingEndEvent($index, $block->thinking, $this->snapshotAssistant($message, $partialContent)));

                continue;
            }

            if ($block instanceof TextContent) {
                $partialContent[] = new TextContent('');
                $stream->push(new TextStartEvent($index, $this->snapshotAssistant($message, $partialContent)));
                foreach ($this->splitString($block->text) as $chunk) {
                    /** @var TextContent $existing */
                    $existing = $partialContent[$index];
                    $partialContent[$index] = new TextContent($existing->text.$chunk);
                    $stream->push(new TextDeltaEvent($index, $chunk, $this->snapshotAssistant($message, $partialContent)));
                }
                $stream->push(new TextEndEvent($index, $block->text, $this->snapshotAssistant($message, $partialContent)));

                continue;
            }

            $partialContent[] = new ToolCall($block->id, $block->name, []);
            $stream->push(new ToolCallStartEvent($index, $this->snapshotAssistant($message, $partialContent)));
            $json = json_encode($block->arguments, JSON_THROW_ON_ERROR);
            $partialJson = '';
            foreach ($this->splitString($json) as $chunk) {
                $partialJson .= $chunk;
                $partialContent[$index] = new ToolCall($block->id, $block->name, JsonParse::parseStreamingJson($partialJson));
                $stream->push(new ToolCallDeltaEvent($index, $chunk, $this->snapshotAssistant($message, $partialContent)));
            }
            $partialContent[$index] = $block;
            $stream->push(new ToolCallEndEvent($index, $block, $this->snapshotAssistant($message, $partialContent)));
        }

        if (in_array($message->stopReason, [StopReason::Error, StopReason::Aborted], true)) {
            $stream->push(new ErrorEvent($message->stopReason, $message));

            return;
        }

        $stream->push(new DoneEvent($message->stopReason, $message));
    }

    /**
     * @param  array<TextContent|ThinkingContent|ToolCall>  $content
     */
    private function snapshotAssistant(AssistantMessage $message, array $content): AssistantMessage
    {
        return new AssistantMessage(
            content: $content,
            api: $message->api,
            provider: $message->provider,
            model: $message->model,
            usage: $message->usage,
            stopReason: $message->stopReason,
            timestamp: $message->timestamp,
            responseId: $message->responseId,
        );
    }

    /**
     * @param  array<TextContent|ThinkingContent|ToolCall>  $content
     */
    private function assistantContentToText(array $content): string
    {
        return implode("\n", array_map(static function (mixed $block): string {
            if ($block instanceof TextContent) {
                return $block->text;
            }

            if ($block instanceof ThinkingContent) {
                return $block->thinking;
            }

            return sprintf('%s:%s', $block->name, json_encode($block->arguments, JSON_THROW_ON_ERROR));
        }, $content));
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * @param  array<Message>  $messages
     */
    private function serializeContext(Context $context): string
    {
        $parts = [];
        if ($context->systemPrompt !== null) {
            $parts[] = sprintf('system:%s', $context->systemPrompt);
        }

        foreach ($context->messages as $message) {
            $parts[] = sprintf('%s:%s', $message->getRole()->value, $this->messageToText($message));
        }

        if ($context->tools !== []) {
            $parts[] = sprintf('tools:%s', json_encode($context->tools, JSON_THROW_ON_ERROR));
        }

        return implode("\n\n", $parts);
    }

    private function messageToText(Message $message): string
    {
        if ($message instanceof UserMessage) {
            if (is_string($message->content)) {
                return $message->content;
            }

            return implode("\n", array_map(static function (TextContent|ImageContent $block): string {
                return $block instanceof TextContent ? $block->text : sprintf('[image:%s:%d]', $block->mimeType, strlen($block->data));
            }, $message->content));
        }

        if ($message instanceof AssistantMessage) {
            return $this->assistantContentToText($message->content);
        }

        return implode("\n", array_merge([$message->toolName], array_map(static function (TextContent|ImageContent $block): string {
            return $block instanceof TextContent ? $block->text : sprintf('[image:%s:%d]', $block->mimeType, strlen($block->data));
        }, $message->content)));
    }

    private function commonPrefixLength(string $a, string $b): int
    {
        $length = min(strlen($a), strlen($b));
        $index = 0;
        while ($index < $length && $a[$index] === $b[$index]) {
            $index++;
        }

        return $index;
    }

    /**
     * @return array<int, string>
     */
    private function splitString(string $text): array
    {
        $tokenSize = max(1, $this->registration->tokenMin);
        $charSize = max(1, $tokenSize * 4);
        $chunks = str_split($text, $charSize);

        return $chunks === [] ? [''] : $chunks;
    }
}
