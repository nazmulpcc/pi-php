<?php

declare(strict_types=1);

namespace Pi\AI\Transport;

final class SseParser
{
    public static function parseFrame(string $frame): ?array
    {
        $dataLines = [];
        $eventType = null;
        foreach (preg_split('/\r?\n/', $frame) as $line) {
            if (str_starts_with($line, 'event:')) {
                $eventType = ltrim(substr($line, 6));

                continue;
            }
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
        if (! is_array($decoded)) {
            return null;
        }

        if ($eventType !== null) {
            $decoded['_eventType'] = $eventType;
        }

        return $decoded;
    }
}
