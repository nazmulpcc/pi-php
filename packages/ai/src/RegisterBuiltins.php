<?php

declare(strict_types=1);

namespace Pi\AI;

use Pi\AI\OpenAI\OpenAIResponsesProvider;

final class RegisterBuiltins
{
    private const SOURCE_ID = 'builtin-providers';

    private static bool $registered = false;

    public static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }

        if (ApiRegistry::getProvider(new Api(Api::OPENAI_RESPONSES)) === null) {
            ApiRegistry::registerProvider(new OpenAIResponsesProvider, self::SOURCE_ID);
        }

        self::$registered = true;
    }

    public static function reset(): void
    {
        ApiRegistry::unregisterProviders(self::SOURCE_ID);
        self::$registered = false;
    }
}
