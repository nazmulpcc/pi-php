<?php

declare(strict_types=1);

namespace Pi\AI;

use Pi\AI\Message\AssistantMessage;
use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthProviderInterface;
use Pi\AI\OAuth\OAuthProviderRegistry;
use Pi\AI\Support\JsonParse;
use Pi\AI\Support\Overflow;
use Pi\AI\Support\SanitizeUnicode;
use Pi\AI\Support\TransformMessages;
use Pi\AI\Support\Validation;
use React\Promise\PromiseInterface;
use RuntimeException;

function packageRoot(string $path = ''): string
{
    $root = dirname(__DIR__);

    if ($path === '') {
        return $root;
    }

    return $root.'/'.ltrim($path, '/');
}

function loadGeneratedModels(): array
{
    $models = require packageRoot('src/models.generated.php');

    if (! is_array($models)) {
        throw new RuntimeException('The generated model catalog must return an array.');
    }

    return $models;
}

function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
{
    return Stream::stream($model, $context, $options);
}

/**
 * @return PromiseInterface<AssistantMessage>
 */
function complete(Model $model, Context $context, ?StreamOptions $options = null): PromiseInterface
{
    return Stream::complete($model, $context, $options);
}

function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
{
    return Stream::streamSimple($model, $context, $options);
}

/**
 * @return PromiseInterface<AssistantMessage>
 */
function completeSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): PromiseInterface
{
    return Stream::completeSimple($model, $context, $options);
}

function getEnvApiKey(string $provider): ?string
{
    return EnvApiKeys::getEnvApiKey($provider);
}

function getModel(Provider|string $provider, string $modelId): ?Model
{
    return Models::getModel($provider, $modelId);
}

/**
 * @return array<int, Provider>
 */
function getProviders(): array
{
    return Models::getProviders();
}

/**
 * @return array<int, Model>
 */
function getModels(Provider|string $provider): array
{
    return Models::getModels($provider);
}

function calculateCost(Model $model, Usage $usage): UsageCost
{
    return Models::calculateCost($model, $usage);
}

function supportsXhigh(Model $model): bool
{
    return Models::supportsXhigh($model);
}

function modelsAreEqual(?Model $a, ?Model $b): bool
{
    return Models::modelsAreEqual($a, $b);
}

/**
 * @param  array<Tool>  $tools
 * @return array<string, mixed>
 */
function validateToolCall(array $tools, Content\ToolCall $toolCall): array
{
    return Validation::validateToolCall($tools, $toolCall);
}

/**
 * @return array<string, mixed>
 */
function validateToolArguments(Tool $tool, Content\ToolCall $toolCall): array
{
    return Validation::validateToolArguments($tool, $toolCall);
}

/**
 * @param  array<Message\Message>  $messages
 * @return array<Message\Message>
 */
function transformMessages(array $messages, Model $model, ?callable $normalizeToolCallId = null): array
{
    return TransformMessages::transformMessages($messages, $model, $normalizeToolCallId);
}

function isContextOverflow(AssistantMessage $message, int $contextWindow): bool
{
    return Overflow::isContextOverflow($message, $contextWindow);
}

function repairJson(string $json): string
{
    return JsonParse::repairJson($json);
}

/**
 * @return array<string, mixed>
 */
function parseJsonWithRepair(string $json): array
{
    return JsonParse::parseJsonWithRepair($json);
}

/**
 * @return array<string, mixed>
 */
function parseStreamingJson(?string $partialJson): array
{
    return JsonParse::parseStreamingJson($partialJson);
}

function sanitizeSurrogates(string $text): string
{
    return SanitizeUnicode::sanitizeSurrogates($text);
}

function fauxText(string $text): Content\TextContent
{
    return Faux::text($text);
}

function fauxThinking(string $thinking): Content\ThinkingContent
{
    return Faux::thinking($thinking);
}

/**
 * @param  array<string, mixed>  $arguments
 * @param  array<string, mixed>  $options
 */
function fauxToolCall(string $name, array $arguments, array $options = []): Content\ToolCall
{
    return Faux::toolCall($name, $arguments, $options);
}

/**
 * @param  string|Content\TextContent|Content\ThinkingContent|Content\ToolCall|array<Content\TextContent|Content\ThinkingContent|Content\ToolCall>  $content
 * @param  array<string, mixed>  $options
 */
function fauxAssistantMessage(string|Content\TextContent|Content\ThinkingContent|Content\ToolCall|array $content, array $options = []): AssistantMessage
{
    return Faux::assistantMessage($content, $options);
}

/**
 * @param  array<string, mixed>  $options
 */
function registerFauxProvider(array $options = []): FauxProviderRegistration
{
    return Faux::registerProvider($options);
}

function getOAuthProvider(string $id): ?OAuthProviderInterface
{
    return OAuthProviderRegistry::get($id);
}

/**
 * @return array<OAuthProviderInterface>
 */
function getOAuthProviders(): array
{
    return OAuthProviderRegistry::all();
}

function registerOAuthProvider(OAuthProviderInterface $provider): void
{
    OAuthProviderRegistry::register($provider);
}

function unregisterOAuthProvider(string $id): void
{
    OAuthProviderRegistry::unregister($id);
}

function resetOAuthProviders(): void
{
    OAuthProviderRegistry::reset();
}

/**
 * @param  array<string, OAuthCredentials|array<string, mixed>>  $credentials
 * @return PromiseInterface<array{newCredentials: OAuthCredentials, apiKey: string}|null>
 */
function getOAuthApiKey(string $providerId, array $credentials): PromiseInterface
{
    return OAuthProviderRegistry::getApiKey($providerId, $credentials);
}
