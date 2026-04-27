<?php

declare(strict_types=1);

namespace Pi\AI;

final class LazyApiProvider implements ApiProviderInterface
{
    private ?ApiProviderInterface $delegate = null;

    public function __construct(
        private readonly string $api,
        private readonly \Closure $factory,
    ) {}

    public function getApi(): Api
    {
        return new Api($this->api);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        return $this->resolve()->stream($model, $context, $options);
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        return $this->resolve()->streamSimple($model, $context, $options);
    }

    private function resolve(): ApiProviderInterface
    {
        if ($this->delegate === null) {
            $this->delegate = ($this->factory)();
        }

        return $this->delegate;
    }
}
