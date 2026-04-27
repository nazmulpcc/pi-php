<?php

declare(strict_types=1);

namespace Pi\AI\Google\GeminiCli;

use Pi\AI\Api;
use Pi\AI\ApiProviderInterface;
use Pi\AI\AssistantMessageEventStream;
use Pi\AI\Context;
use Pi\AI\Model;
use Pi\AI\SimpleStreamOptions;
use Pi\AI\StreamOptions;

final readonly class GoogleGeminiCliProvider implements ApiProviderInterface
{
    public function getApi(): Api
    {
        return new Api(Api::GOOGLE_GEMINI_CLI);
    }

    public function stream(Model $model, Context $context, ?StreamOptions $options = null): AssistantMessageEventStream
    {
        throw new \RuntimeException(
            'Google Gemini CLI provider is not yet implemented in the PHP runtime. '.
            'This provider requires OAuth authentication, which is not supported. '.
            'Use the Google Generative AI provider (google-generative-ai) instead.'
        );
    }

    public function streamSimple(Model $model, Context $context, ?SimpleStreamOptions $options = null): AssistantMessageEventStream
    {
        return $this->stream($model, $context);
    }
}
