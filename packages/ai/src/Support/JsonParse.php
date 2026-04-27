<?php

declare(strict_types=1);

namespace Pi\AI\Support;

final class JsonParse
{
    public static function repairJson(string $json): string
    {
        $repaired = '';
        $inString = false;
        $length = strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $char = $json[$index];

            if (! $inString) {
                $repaired .= $char;
                if ($char === '"') {
                    $inString = true;
                }

                continue;
            }

            if ($char === '"') {
                $repaired .= $char;
                $inString = false;

                continue;
            }

            if ($char === '\\') {
                $next = $json[$index + 1] ?? null;

                if ($next === null) {
                    $repaired .= '\\\\';

                    continue;
                }

                if ($next === 'u') {
                    $digits = substr($json, $index + 2, 4);
                    if (preg_match('/^[0-9a-fA-F]{4}$/', $digits) === 1) {
                        $repaired .= "\\u{$digits}";
                        $index += 5;

                        continue;
                    }
                }

                if (in_array($next, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)) {
                    $repaired .= "\\{$next}";
                    $index++;

                    continue;
                }

                $repaired .= '\\\\';

                continue;
            }

            $ordinal = ord($char);
            if ($ordinal >= 0 && $ordinal <= 31) {
                $repaired .= match ($char) {
                    "\b" => '\\b',
                    "\f" => '\\f',
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    default => sprintf('\\u%04x', $ordinal),
                };

                continue;
            }

            $repaired .= $char;
        }

        return $repaired;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseJsonWithRepair(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            $repaired = self::repairJson($json);
            $decoded = json_decode($repaired, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseStreamingJson(?string $partialJson): array
    {
        if ($partialJson === null || trim($partialJson) === '') {
            return [];
        }

        try {
            return self::parseJsonWithRepair($partialJson);
        } catch (\JsonException) {
            try {
                return self::parseJsonWithRepair(self::completePartialJson($partialJson));
            } catch (\JsonException) {
                return [];
            }
        }
    }

    private static function completePartialJson(string $json): string
    {
        $repaired = self::repairJson($json);

        if (substr_count($repaired, '"') % 2 === 1) {
            $repaired .= '"';
        }

        $openBraces = substr_count($repaired, '{') - substr_count($repaired, '}');
        $openBrackets = substr_count($repaired, '[') - substr_count($repaired, ']');

        if (preg_match('/[,\[:]\s*$/', $repaired) === 1) {
            $repaired = rtrim($repaired, ", \t\n\r\0\x0B");
        }

        if ($openBrackets > 0) {
            $repaired .= str_repeat(']', $openBrackets);
        }

        if ($openBraces > 0) {
            $repaired .= str_repeat('}', $openBraces);
        }

        return $repaired;
    }
}
