<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

final class Pkce
{
    /**
     * @return array{verifier:string, challenge:string}
     */
    public static function generate(): array
    {
        $verifier = self::base64UrlEncode(random_bytes(32));
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));

        return [
            'verifier' => $verifier,
            'challenge' => $challenge,
        ];
    }

    public static function createState(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function decodeBase64UrlJsonSegment(string $segment): ?array
    {
        $segment .= str_repeat('=', (4 - strlen($segment) % 4) % 4);
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if (! is_string($decoded)) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
