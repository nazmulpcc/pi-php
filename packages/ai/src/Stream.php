<?php

declare(strict_types=1);

namespace Pi\AI;

use Pi\AI\ApiProviderInterface as RootApiProviderInterface;
use Pi\AI\Message\AssistantMessage;
use React\Promise\PromiseInterface;

final class Stream
{
    public static function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $provider = self::resolveApiProvider($model->api);

        return $provider->stream($model, $context, $options);
    }

    /**
     * @return PromiseInterface<AssistantMessage>
     */
    public static function complete(Model $model, Context $context, ?StreamOptions $options = null): PromiseInterface
    {
        return self::stream($model, $context, $options)->result();
    }

    public static function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $provider = self::resolveApiProvider($model->api);

        return $provider->streamSimple($model, $context, $options);
    }

    /**
     * @return PromiseInterface<AssistantMessage>
     */
    public static function completeSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): PromiseInterface
    {
        return self::streamSimple($model, $context, $options)->result();
    }

    private static function resolveApiProvider(Api $api): RootApiProviderInterface
    {
        RegisterBuiltins::ensureRegistered();

        /** @var RootApiProviderInterface|null $provider */
        $provider = ApiRegistry::getProvider($api);
        if (! $provider instanceof RootApiProviderInterface) {
            throw new \RuntimeException(sprintf('No API provider registered for api: %s', $api->value));
        }

        return $provider;
    }
}
