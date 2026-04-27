<?php

declare(strict_types=1);

namespace Pi\AI;

use InvalidArgumentException;

final readonly class RegisteredApiProvider implements ApiProviderInterface
{
    public function __construct(
        private ApiProviderInterface $provider,
    ) {}

    public function getApi(): Api
    {
        return $this->provider->getApi();
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        $this->assertMatchingApi($model);

        return $this->provider->stream($model, $context, $options);
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        $this->assertMatchingApi($model);

        return $this->provider->streamSimple($model, $context, $options);
    }

    private function assertMatchingApi(Model $model): void
    {
        if (! $model->api->equals($this->provider->getApi())) {
            throw new InvalidArgumentException(sprintf(
                'Mismatched api: %s expected %s',
                $model->api->value,
                $this->provider->getApi()->value,
            ));
        }
    }
}
