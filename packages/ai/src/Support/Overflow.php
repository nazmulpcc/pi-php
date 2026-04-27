<?php

declare(strict_types=1);

namespace Pi\AI\Support;

use Pi\AI\Message\AssistantMessage;

final class Overflow
{
    public static function isContextOverflow(AssistantMessage $message, int $contextWindow): bool
    {
        if ($message->errorMessage === null || $message->errorMessage === '') {
            return false;
        }

        $error = strtolower($message->errorMessage);

        if (str_contains($error, 'throttling') || str_contains($error, 'rate limit') || str_contains($error, 'too many requests')) {
            return false;
        }

        return str_contains($error, 'prompt too long')
            || str_contains($error, 'max context length')
            || str_contains($error, 'context window exceeded')
            || str_contains($error, sprintf('context length by %d', $contextWindow));
    }
}
