<?php

declare(strict_types=1);

namespace Pi\AI\Transport;

final class SseParser
{
    public static function parseFrame(string $frame): ?array
    {
        $dataLines = [];
        foreach (preg_split('/\r?\n/', $frame) as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $dataLines[] = ltrim(substr($line, 5));
        }

        if ($dataLines === []) {
            return null;
        }

        $payload = implode("\n", $dataLines);
        if ($payload === '[DONE]') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
