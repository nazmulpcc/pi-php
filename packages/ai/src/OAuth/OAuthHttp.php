<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

use Pi\AI\Support\PromiseHelper;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;

final class OAuthHttp
{
    private static mixed $client = null;

    public static function setClientForTesting(?callable $client): void
    {
        self::$client = $client;
    }

    /**
     * @param  array<string, string>  $headers
     * @return PromiseInterface<array{status:int, body:string}>
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null): PromiseInterface
    {
        if (self::$client !== null) {
            return PromiseHelper::resolve((self::$client)($method, $url, $headers, $body));
        }

        $browser = new Browser(Loop::get());

        return $browser->request($method, $url, $headers, $body ?? '')
            ->then(static function (ResponseInterface $response): array {
                return [
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                ];
            });
    }
}
