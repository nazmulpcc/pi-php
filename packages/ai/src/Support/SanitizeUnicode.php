<?php

declare(strict_types=1);

namespace Pi\AI\Support;

final class SanitizeUnicode
{
    public static function sanitizeSurrogates(string $text): string
    {
        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return $sanitized === false ? $text : $sanitized;
    }
}
