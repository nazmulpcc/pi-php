<?php

declare(strict_types=1);

namespace Pi\AI\Bedrock;

use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\CacheRetention;
use Pi\AI\Content\ImageContent;
use Pi\AI\Content\TextContent;
use Pi\AI\Content\ThinkingContent;
use Pi\AI\Content\ToolCall;
use Pi\AI\Context;
use Pi\AI\EnvApiKeys;
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
use Pi\AI\Message\ToolResultMessage;
use Pi\AI\Message\UserMessage;
use Pi\AI\Model;
use Pi\AI\Models;
use Pi\AI\Provider;
use Pi\AI\Schema\Schema;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StopReason;
use Pi\AI\StreamOptions;
use Pi\AI\Support\JsonParse;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\SimpleOptions;
use Pi\AI\Support\TransformMessages;
use Pi\AI\ThinkingLevel;
use Pi\AI\Tool;
use Pi\AI\Transport\HttpResponse;
use Pi\AI\Transport\HttpTransport;
use Pi\AI\Transport\ProviderError;
use Pi\AI\Usage;

final readonly class BedrockProvider implements ApiProviderInterface
{
    /**
     * @param  null|callable(Model, Context, BedrockOptions, array<string, mixed>): iterable<array<string, mixed>>  $transport
     */
    public function __construct(
        private ?\Closure $transport = null,
    ) {}

    public function getApi(): Api
    {
        return new Api(Api::BEDROCK_CONVERSE_STREAM);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $stream = new AssistantMessageEventStream;
        $providerOptions = $options instanceof BedrockOptions
            ? $options
            : self::mapToProviderOptions($options);

        $blocks = [];
        $blockStates = [];
        $output = $this->createOutput($model);
        $started = false;

        try {
            $params = $this->buildParams($model, $context, $providerOptions);
            $nextParams = $providerOptions->onPayload?->__invoke($params, $model);
            if (is_array($nextParams)) {
                $params = $nextParams;
            }

            $events = $this->transport !== null
                ? ($this->transport)($model, $context, $providerOptions, $params)
                : self::parseResponseEvents($this->request($model, $providerOptions, $params)->body);

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                if (isset($event['messageStart']) && is_array($event['messageStart'])) {
                    $started = true;
                    $stream->push(new StartEvent($output));

                    continue;
                }

                if (! $started) {
                    $started = true;
                    $stream->push(new StartEvent($output));
                }

                if (isset($event['contentBlockStart']) && is_array($event['contentBlockStart'])) {
                    $output = $this->handleContentBlockStart($event['contentBlockStart'], $model, $blocks, $blockStates, $output, $stream);

                    continue;
                }

                if (isset($event['contentBlockDelta']) && is_array($event['contentBlockDelta'])) {
                    $output = $this->handleContentBlockDelta($event['contentBlockDelta'], $model, $blocks, $blockStates, $output, $stream);

                    continue;
                }

                if (isset($event['contentBlockStop']) && is_array($event['contentBlockStop'])) {
                    $output = $this->handleContentBlockStop($event['contentBlockStop'], $model, $blocks, $blockStates, $output, $stream);

                    continue;
                }

                if (isset($event['messageStop']) && is_array($event['messageStop'])) {
                    $output = $this->snapshot(
                        $model,
                        $blocks,
                        $output->usage,
                        self::mapStopReason(is_string($event['messageStop']['stopReason'] ?? null) ? $event['messageStop']['stopReason'] : null),
                        $output->responseId,
                        $output->errorMessage,
                    );

                    continue;
                }

                if (isset($event['metadata']) && is_array($event['metadata'])) {
                    $output = $this->handleMetadata($event['metadata'], $model, $blocks, $output);

                    continue;
                }

                foreach (['internalServerException', 'modelStreamErrorException', 'validationException', 'throttlingException', 'serviceUnavailableException'] as $key) {
                    if (isset($event[$key])) {
                        throw new ProviderError(self::extractErrorMessage($event[$key], $key));
                    }
                }
            }

            if ($providerOptions->signal?->isCancelled()) {
                throw new ProviderError('Request was aborted', 0, 'aborted');
            }

            if ($output->stopReason === StopReason::Aborted) {
                throw new ProviderError('Request was aborted', 0, 'aborted');
            }

            if ($output->stopReason === StopReason::Error) {
                throw new ProviderError($output->errorMessage ?: 'An unknown error occurred');
            }

            if (! $started) {
                $stream->push(new StartEvent($output));
            }

            $stream->push(new DoneEvent($output->stopReason, $output));
            $stream->end();
        } catch (\Throwable $error) {
            $output = $this->snapshot(
                $model,
                $blocks,
                $output->usage,
                $options?->signal?->isCancelled() ? StopReason::Aborted : StopReason::Error,
                $output->responseId,
                $error->getMessage(),
            );
            $stream->push(new ErrorEvent($output->stopReason, $output));
            $stream->end($output);
        }

        return $stream;
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $base = SimpleOptions::buildBaseOptions($model, $options, $options?->apiKey);

        if (! $options?->reasoning) {
            return $this->stream($model, $context, new BedrockOptions(
                ...get_object_vars($base),
                reasoning: null,
            ));
        }

        if (self::supportsAdaptiveThinking($model)) {
            return $this->stream($model, $context, new BedrockOptions(
                ...get_object_vars($base),
                reasoning: $options->reasoning,
                thinkingBudgets: $options->thinkingBudgets,
            ));
        }

        $adjusted = SimpleOptions::adjustMaxTokensForThinking(
            $base->maxTokens ?? 0,
            $model->maxTokens,
            $options->reasoning,
            $options->thinkingBudgets,
        );

        return $this->stream($model, $context, new BedrockOptions(
            ...get_object_vars($base),
            maxTokens: $adjusted['maxTokens'],
            reasoning: $options->reasoning,
            thinkingBudgets: array_merge($options->thinkingBudgets, [
                SimpleOptions::clampReasoning($options->reasoning)?->value ?? ThinkingLevel::Medium->value => $adjusted['thinkingBudget'],
            ]),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(Model $model, Context $context, BedrockOptions $options): array
    {
        $params = [
            'modelId' => $model->id,
            'messages' => self::convertMessages($context, $model),
        ];

        if ($context->systemPrompt !== null && trim($context->systemPrompt) !== '') {
            $params['system'] = [['text' => SanitizeUnicode::sanitizeSurrogates($context->systemPrompt)]];
        }

        $inferenceConfig = [];
        if ($options->maxTokens !== null) {
            $inferenceConfig['maxTokens'] = $options->maxTokens;
        }
        if ($options->temperature !== null) {
            $inferenceConfig['temperature'] = $options->temperature;
        }
        if ($inferenceConfig !== []) {
            $params['inferenceConfig'] = $inferenceConfig;
        }

        $toolConfig = self::convertToolConfig($context->tools, $options->toolChoice);
        if ($toolConfig !== null) {
            $params['toolConfig'] = $toolConfig;
        }

        $additionalModelRequestFields = self::buildAdditionalModelRequestFields($model, $options);
        if ($additionalModelRequestFields !== null) {
            $params['additionalModelRequestFields'] = $additionalModelRequestFields;
        }

        if ($options->requestMetadata !== []) {
            $params['requestMetadata'] = $options->requestMetadata;
        }

        return $params;
    }

    private function request(Model $model, BedrockOptions $options, array $params): HttpResponse
    {
        $url = rtrim($model->baseUrl, '/').'/model/'.rawurlencode($model->id).'/converse-stream';
        $headers = array_merge($model->headers, $options->headers, $this->buildAuthHeaders($options));

        $onResponse = $options->onResponse !== null
            ? static function (array $response) use ($options, $model): void {
                $options->onResponse->__invoke([
                    'status' => $response['status'],
                    'headers' => $response['headers'],
                ], $model);
            }
        : null;

        $transport = new HttpTransport(
            signal: $options->signal,
            timeoutMs: $options->timeoutMs,
            maxRetries: $options->maxRetries,
            maxRetryDelayMs: $options->maxRetryDelayMs,
        );

        return $transport->request('POST', $url, [
            'headers' => $headers,
            'body' => $params,
            'onResponse' => $onResponse,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(BedrockOptions $options): array
    {
        $bearerToken = $options->bearerToken ?: $options->apiKey ?: EnvApiKeys::getEnvApiKey(Provider::AMAZON_BEDROCK) ?: null;
        $region = $options->region ?: (getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION') ?: null);

        if (is_string($bearerToken) && $bearerToken !== '') {
            $headers = ['Authorization' => 'Bearer '.$bearerToken];
            if (is_string($region) && $region !== '') {
                $headers['x-aws-region'] = $region;
            }

            return $headers;
        }

        $profile = $options->profile ?: (getenv('AWS_PROFILE') ?: null);
        if (is_string($profile) && $profile !== '') {
            $headers = ['x-aws-profile' => $profile];
            if (is_string($region) && $region !== '') {
                $headers['x-aws-region'] = $region;
            }

            return $headers;
        }

        throw new \RuntimeException('No Bedrock auth configured. Set bearerToken, AWS_BEARER_TOKEN_BEDROCK, or AWS_PROFILE. AWS credential pair auth requires SigV4 signing, which is not yet implemented in the PHP runtime.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseResponseEvents(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (isset($decoded['stream']) && is_array($decoded['stream'])) {
                return array_values(array_filter($decoded['stream'], 'is_array'));
            }
            if (array_is_list($decoded)) {
                return array_values(array_filter($decoded, 'is_array'));
            }
        }

        $events = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     * @param  array<int, array{contentIndex: int, type: string, partialJson?: string}>  $blockStates
     */
    private function handleContentBlockStart(array $event, Model $model, array &$blocks, array &$blockStates, AssistantMessage $output, AssistantMessageEventStream $stream): AssistantMessage
    {
        $index = isset($event['contentBlockIndex']) ? (int) $event['contentBlockIndex'] : 0;
        $start = isset($event['start']) && is_array($event['start']) ? $event['start'] : [];
        $toolUse = isset($start['toolUse']) && is_array($start['toolUse']) ? $start['toolUse'] : null;

        if ($toolUse === null) {
            return $output;
        }

        $toolCall = new ToolCall(
            id: is_string($toolUse['toolUseId'] ?? null) ? $toolUse['toolUseId'] : '',
            name: is_string($toolUse['name'] ?? null) ? $toolUse['name'] : '',
            arguments: [],
        );
        $blocks[] = $toolCall;
        $contentIndex = count($blocks) - 1;
        $blockStates[$index] = ['contentIndex' => $contentIndex, 'type' => 'toolCall', 'partialJson' => ''];
        $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
        $stream->push(new ToolCallStartEvent($contentIndex, $output));

        return $output;
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     * @param  array<int, array{contentIndex: int, type: string, partialJson?: string}>  $blockStates
     */
    private function handleContentBlockDelta(array $event, Model $model, array &$blocks, array &$blockStates, AssistantMessage $output, AssistantMessageEventStream $stream): AssistantMessage
    {
        $index = isset($event['contentBlockIndex']) ? (int) $event['contentBlockIndex'] : 0;
        $delta = isset($event['delta']) && is_array($event['delta']) ? $event['delta'] : [];
        $state = $blockStates[$index] ?? null;

        if (isset($delta['text']) && is_string($delta['text'])) {
            if ($state === null) {
                $blocks[] = new TextContent('');
                $contentIndex = count($blocks) - 1;
                $blockStates[$index] = ['contentIndex' => $contentIndex, 'type' => 'text'];
                $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
                $stream->push(new TextStartEvent($contentIndex, $output));
                $state = $blockStates[$index];
            }

            $contentIndex = $state['contentIndex'];
            $current = $blocks[$contentIndex] instanceof TextContent ? $blocks[$contentIndex] : new TextContent('');
            $blocks[$contentIndex] = new TextContent($current->text.$delta['text']);
            $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
            $stream->push(new TextDeltaEvent($contentIndex, $delta['text'], $output));

            return $output;
        }

        $toolUse = isset($delta['toolUse']) && is_array($delta['toolUse']) ? $delta['toolUse'] : null;
        if ($toolUse !== null && $state !== null && $state['type'] === 'toolCall') {
            $chunk = is_string($toolUse['input'] ?? null) ? $toolUse['input'] : '';
            $partialJson = ($state['partialJson'] ?? '').$chunk;
            $blockStates[$index]['partialJson'] = $partialJson;
            $contentIndex = $state['contentIndex'];
            $current = $blocks[$contentIndex] instanceof ToolCall ? $blocks[$contentIndex] : new ToolCall('', '', []);
            $blocks[$contentIndex] = new ToolCall(
                id: $current->id,
                name: $current->name,
                arguments: JsonParse::parseStreamingJson($partialJson),
                thoughtSignature: $current->thoughtSignature,
            );
            $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
            $stream->push(new ToolCallDeltaEvent($contentIndex, $chunk, $output));

            return $output;
        }

        $reasoningContent = isset($delta['reasoningContent']) && is_array($delta['reasoningContent']) ? $delta['reasoningContent'] : null;
        if ($reasoningContent !== null) {
            if ($state === null) {
                $blocks[] = new ThinkingContent('');
                $contentIndex = count($blocks) - 1;
                $blockStates[$index] = ['contentIndex' => $contentIndex, 'type' => 'thinking'];
                $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);
                $stream->push(new ThinkingStartEvent($contentIndex, $output));
                $state = $blockStates[$index];
            }

            $contentIndex = $state['contentIndex'];
            $current = $blocks[$contentIndex] instanceof ThinkingContent ? $blocks[$contentIndex] : new ThinkingContent('');
            $thinking = $current->thinking;
            $signature = $current->thinkingSignature;

            if (is_string($reasoningContent['text'] ?? null)) {
                $thinking .= $reasoningContent['text'];
            }
            if (is_string($reasoningContent['signature'] ?? null)) {
                $signature = ($signature ?? '').$reasoningContent['signature'];
            }

            $blocks[$contentIndex] = new ThinkingContent($thinking, $signature);
            $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);

            if (is_string($reasoningContent['text'] ?? null) && $reasoningContent['text'] !== '') {
                $stream->push(new ThinkingDeltaEvent($contentIndex, $reasoningContent['text'], $output));
            }
        }

        return $output;
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     * @param  array<int, array{contentIndex: int, type: string, partialJson?: string}>  $blockStates
     */
    private function handleContentBlockStop(array $event, Model $model, array &$blocks, array &$blockStates, AssistantMessage $output, AssistantMessageEventStream $stream): AssistantMessage
    {
        $index = isset($event['contentBlockIndex']) ? (int) $event['contentBlockIndex'] : 0;
        $state = $blockStates[$index] ?? null;
        if ($state === null) {
            return $output;
        }

        $contentIndex = $state['contentIndex'];
        $block = $blocks[$contentIndex] ?? null;
        if ($block === null) {
            return $output;
        }

        if ($block instanceof ToolCall) {
            $block = new ToolCall(
                id: $block->id,
                name: $block->name,
                arguments: JsonParse::parseStreamingJson($state['partialJson'] ?? ''),
                thoughtSignature: $block->thoughtSignature,
            );
            $blocks[$contentIndex] = $block;
        }

        $output = $this->snapshot($model, $blocks, $output->usage, $output->stopReason, $output->responseId, $output->errorMessage);

        if ($block instanceof TextContent) {
            $stream->push(new TextEndEvent($contentIndex, $block->text, $output));
        } elseif ($block instanceof ThinkingContent) {
            $stream->push(new ThinkingEndEvent($contentIndex, $block->thinking, $output));
        } elseif ($block instanceof ToolCall) {
            $stream->push(new ToolCallEndEvent($contentIndex, $block, $output));
        }

        unset($blockStates[$index]);

        return $output;
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     */
    private function handleMetadata(array $event, Model $model, array $blocks, AssistantMessage $output): AssistantMessage
    {
        $usageData = isset($event['usage']) && is_array($event['usage']) ? $event['usage'] : [];
        $usage = new Usage(
            input: (int) ($usageData['inputTokens'] ?? 0),
            output: (int) ($usageData['outputTokens'] ?? 0),
            cacheRead: (int) ($usageData['cacheReadInputTokens'] ?? 0),
            cacheWrite: (int) ($usageData['cacheWriteInputTokens'] ?? 0),
            totalTokens: isset($usageData['totalTokens']) ? (int) $usageData['totalTokens'] : 0,
        );
        if ($usage->totalTokens === 0) {
            $usage = new Usage(
                input: $usage->input,
                output: $usage->output,
                cacheRead: $usage->cacheRead,
                cacheWrite: $usage->cacheWrite,
                totalTokens: $usage->input + $usage->output + $usage->cacheRead + $usage->cacheWrite,
            );
        }
        $usage = new Usage(
            input: $usage->input,
            output: $usage->output,
            cacheRead: $usage->cacheRead,
            cacheWrite: $usage->cacheWrite,
            totalTokens: $usage->totalTokens,
            cost: Models::calculateCost($model, $usage),
        );

        return $this->snapshot($model, $blocks, $usage, $output->stopReason, $output->responseId, $output->errorMessage);
    }

    private function createOutput(Model $model): AssistantMessage
    {
        return new AssistantMessage(
            content: [],
            api: $model->api,
            provider: $model->provider,
            model: $model->id,
            usage: Usage::zero(),
            stopReason: StopReason::Stop,
            timestamp: time(),
        );
    }

    /**
     * @param  array<int, TextContent|ThinkingContent|ToolCall>  $blocks
     */
    private function snapshot(Model $model, array $blocks, Usage $usage, StopReason $stopReason, ?string $responseId, ?string $errorMessage): AssistantMessage
    {
        return new AssistantMessage(
            content: array_values($blocks),
            api: $model->api,
            provider: $model->provider,
            model: $model->id,
            usage: $usage,
            stopReason: $stopReason,
            timestamp: time(),
            responseId: $responseId,
            errorMessage: $errorMessage,
        );
    }

    private static function mapToProviderOptions(?StreamOptions $options): BedrockOptions
    {
        return new BedrockOptions(
            temperature: $options?->temperature,
            maxTokens: $options?->maxTokens,
            signal: $options?->signal,
            apiKey: $options?->apiKey,
            transport: $options?->transport,
            cacheRetention: $options?->cacheRetention ?? CacheRetention::Short,
            sessionId: $options?->sessionId,
            onPayload: $options?->onPayload,
            onResponse: $options?->onResponse,
            headers: $options?->headers ?? [],
            timeoutMs: $options?->timeoutMs,
            maxRetries: $options?->maxRetries,
            maxRetryDelayMs: $options?->maxRetryDelayMs,
            metadata: $options?->metadata ?? [],
        );
    }

    private static function supportsAdaptiveThinking(Model $model): bool
    {
        foreach ([strtolower($model->id), strtolower($model->name)] as $candidate) {
            if (
                str_contains($candidate, 'opus-4-6')
                || str_contains($candidate, 'opus-4.6')
                || str_contains($candidate, 'opus-4-7')
                || str_contains($candidate, 'opus-4.7')
                || str_contains($candidate, 'sonnet-4-6')
                || str_contains($candidate, 'sonnet-4.6')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function isAnthropicClaudeModel(Model $model): bool
    {
        $id = strtolower($model->id);
        $name = strtolower($model->name);

        return str_contains($id, 'anthropic.claude')
            || str_contains($id, 'anthropic/claude')
            || str_contains($name, 'anthropic.claude')
            || str_contains($name, 'anthropic/claude')
            || str_contains($name, 'claude');
    }

    private static function supportsThinkingSignature(Model $model): bool
    {
        return self::isAnthropicClaudeModel($model);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function convertMessages(Context $context, Model $model): array
    {
        $result = [];
        $messages = TransformMessages::transformMessages($context->messages, $model, self::normalizeToolCallId(...));
        $count = count($messages);

        for ($i = 0; $i < $count; $i++) {
            $message = $messages[$i];

            if ($message instanceof UserMessage) {
                $result[] = [
                    'role' => 'user',
                    'content' => self::convertUserContent($message),
                ];

                continue;
            }

            if ($message instanceof AssistantMessage) {
                $content = [];
                foreach ($message->content as $block) {
                    if ($block instanceof TextContent) {
                        $content[] = ['text' => SanitizeUnicode::sanitizeSurrogates($block->text)];

                        continue;
                    }

                    if ($block instanceof ToolCall) {
                        $content[] = ['toolUse' => [
                            'toolUseId' => $block->id,
                            'name' => $block->name,
                            'input' => $block->arguments,
                        ]];

                        continue;
                    }

                    if ($block instanceof ThinkingContent) {
                        $reasoningText = ['text' => SanitizeUnicode::sanitizeSurrogates($block->thinking)];
                        if (self::supportsThinkingSignature($model) && $block->thinkingSignature !== null && $block->thinkingSignature !== '') {
                            $reasoningText['signature'] = $block->thinkingSignature;
                        }
                        $content[] = ['reasoningContent' => ['reasoningText' => $reasoningText]];
                    }
                }

                if ($content !== []) {
                    $result[] = ['role' => 'assistant', 'content' => $content];
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                $content = [];

                do {
                    $toolResult = $messages[$i];
                    if (! $toolResult instanceof ToolResultMessage) {
                        break;
                    }
                    $content[] = ['toolResult' => [
                        'toolUseId' => $toolResult->toolCallId,
                        'content' => self::convertToolResultContent($toolResult),
                        'status' => $toolResult->isError ? 'error' : 'success',
                    ]];
                    $i++;
                } while ($i < $count && $messages[$i] instanceof ToolResultMessage);

                $i--;
                $result[] = ['role' => 'user', 'content' => $content];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function convertUserContent(UserMessage $message): array
    {
        if (is_string($message->content)) {
            return [['text' => SanitizeUnicode::sanitizeSurrogates($message->content)]];
        }

        $content = [];
        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $content[] = ['text' => SanitizeUnicode::sanitizeSurrogates($block->text)];
            }

            if ($block instanceof ImageContent) {
                $content[] = ['image' => self::createImageBlock($block->mimeType, $block->data)];
            }
        }

        return $content;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function convertToolResultContent(ToolResultMessage $message): array
    {
        $content = [];
        foreach ($message->content as $block) {
            if ($block instanceof TextContent) {
                $content[] = ['text' => SanitizeUnicode::sanitizeSurrogates($block->text)];
            }

            if ($block instanceof ImageContent) {
                $content[] = ['image' => self::createImageBlock($block->mimeType, $block->data)];
            }
        }

        return $content;
    }

    /**
     * @param  array<int, Tool>  $tools
     * @return array<string, mixed>|null
     */
    private static function convertToolConfig(array $tools, string|array|null $toolChoice): ?array
    {
        if ($tools === [] || $toolChoice === 'none') {
            return null;
        }

        $bedrockTools = [];
        foreach ($tools as $tool) {
            $bedrockTools[] = [
                'toolSpec' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'inputSchema' => ['json' => $tool->parameters instanceof Schema ? $tool->parameters->toArray() : $tool->parameters],
                ],
            ];
        }

        $config = ['tools' => $bedrockTools];

        if ($toolChoice === 'auto') {
            $config['toolChoice'] = ['auto' => new \stdClass];
        } elseif ($toolChoice === 'any') {
            $config['toolChoice'] = ['any' => new \stdClass];
        } elseif (is_array($toolChoice) && ($toolChoice['type'] ?? null) === 'tool' && is_string($toolChoice['name'] ?? null)) {
            $config['toolChoice'] = ['tool' => ['name' => $toolChoice['name']]];
        }

        return $config;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildAdditionalModelRequestFields(Model $model, BedrockOptions $options): ?array
    {
        if (! $model->reasoning || ! $options->reasoning instanceof ThinkingLevel || ! self::isAnthropicClaudeModel($model)) {
            return null;
        }

        if (self::supportsAdaptiveThinking($model)) {
            return [
                'thinking' => array_filter([
                    'type' => 'adaptive',
                    'display' => $options->thinkingDisplay ?? 'summarized',
                ], static fn (mixed $value): bool => $value !== null),
                'output_config' => ['effort' => self::mapThinkingLevelToEffort($options->reasoning, $model)],
            ];
        }

        $defaultBudgets = [
            ThinkingLevel::Minimal->value => 1024,
            ThinkingLevel::Low->value => 2048,
            ThinkingLevel::Medium->value => 8192,
            ThinkingLevel::High->value => 16384,
            ThinkingLevel::Xhigh->value => 16384,
        ];
        $level = SimpleOptions::clampReasoning($options->reasoning)?->value ?? ThinkingLevel::Medium->value;
        $budget = $options->thinkingBudgets[$level] ?? $defaultBudgets[$options->reasoning->value];

        $result = [
            'thinking' => array_filter([
                'type' => 'enabled',
                'budget_tokens' => $budget,
                'display' => $options->thinkingDisplay ?? 'summarized',
            ], static fn (mixed $value): bool => $value !== null),
        ];

        if ($options->interleavedThinking ?? true) {
            $result['anthropic_beta'] = ['interleaved-thinking-2025-05-14'];
        }

        return $result;
    }

    private static function mapThinkingLevelToEffort(ThinkingLevel $level, Model $model): string
    {
        return match ($level) {
            ThinkingLevel::Minimal, ThinkingLevel::Low => 'low',
            ThinkingLevel::Medium => 'medium',
            ThinkingLevel::High => 'high',
            ThinkingLevel::Xhigh => str_contains(strtolower($model->id.$model->name), 'opus-4-6') ? 'max' : 'xhigh',
        };
    }

    private static function mapStopReason(?string $reason): StopReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence' => StopReason::Stop,
            'max_tokens', 'model_context_window_exceeded' => StopReason::Length,
            'tool_use' => StopReason::ToolUse,
            default => StopReason::Error,
        };
    }

    private static function normalizeToolCallId(string $id): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_-]/', '_', $id) ?: 'toolcall';
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            $normalized = 'toolcall';
        }

        return substr($normalized, 0, 64);
    }

    /**
     * @return array<string, mixed>
     */
    private static function createImageBlock(string $mimeType, string $data): array
    {
        $format = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new \InvalidArgumentException(sprintf('Unknown image type: %s', $mimeType)),
        };

        return [
            'format' => $format,
            'source' => ['bytes' => $data],
        ];
    }

    private static function extractErrorMessage(mixed $error, string $fallback): string
    {
        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (is_array($error) && is_string($error['message'] ?? null) && $error['message'] !== '') {
            return $error['message'];
        }

        return $fallback;
    }
}
