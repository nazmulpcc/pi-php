<?php

declare(strict_types=1);

namespace Pi\AI;

interface ApiProviderInterface
{
    public function getApi(): Api;

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream;

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream;
}
