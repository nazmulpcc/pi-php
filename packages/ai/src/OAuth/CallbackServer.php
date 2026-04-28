<?php

declare(strict_types=1);

namespace Pi\AI\OAuth;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class CallbackServer
{
    private mixed $server = null;

    private mixed $timer = null;

    private bool $closed = false;

    private ?Deferred $deferred = null;

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $path,
        private readonly ?string $expectedState,
        private readonly string $successMessage,
    ) {}

    public static function start(string $host, int $port, string $path, ?string $expectedState, string $successMessage): self
    {
        $server = new self($host, $port, $path, $expectedState, $successMessage);
        $server->boot();

        return $server;
    }

    /**
     * @return PromiseInterface<?array{code:string, state:?string}>
     */
    public function waitForCode(): PromiseInterface
    {
        $this->deferred ??= new Deferred;

        return $this->deferred->promise();
    }

    public function cancelWait(): void
    {
        if ($this->deferred === null) {
            $this->deferred = new Deferred;
        }

        $this->deferred->resolve(null);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (is_resource($this->server)) {
            Loop::removeReadStream($this->server);
            fclose($this->server);
        }

        if ($this->timer !== null) {
            Loop::cancelTimer($this->timer);
        }
    }

    private function boot(): void
    {
        $address = sprintf('tcp://%s:%d', $this->host, $this->port);
        $server = @stream_socket_server($address, $errno, $errstr);
        if (! is_resource($server)) {
            throw new \RuntimeException(sprintf('Failed to start OAuth callback server on %s: %s', $address, $errstr ?: (string) $errno));
        }

        stream_set_blocking($server, false);
        $this->server = $server;
        $this->deferred = new Deferred;

        Loop::addReadStream($server, function ($server): void {
            $client = @stream_socket_accept($server, 0);
            if (! is_resource($client)) {
                return;
            }

            $result = $this->handleClient($client);
            if ($result !== null && $this->deferred !== null) {
                $this->deferred->resolve($result);
            }
        });
    }

    /**
     * @return ?array{code:string, state:?string}
     */
    private function handleClient(mixed $client): ?array
    {
        try {
            stream_set_blocking($client, true);
            stream_set_timeout($client, 2);

            $requestLine = fgets($client);
            if (! is_string($requestLine) || $requestLine === '') {
                $this->writeResponse($client, 400, OAuthPage::error('Missing request line.'));

                return null;
            }

            while (($line = fgets($client)) !== false) {
                if (rtrim($line, "\r\n") === '') {
                    break;
                }
            }

            if (! preg_match('#^[A-Z]+\s+(\S+)#', $requestLine, $matches) || ! isset($matches[1])) {
                $this->writeResponse($client, 400, OAuthPage::error('Malformed callback request.'));

                return null;
            }

            $url = parse_url($matches[1]);
            $path = $url['path'] ?? '/';
            if ($path !== $this->path) {
                $this->writeResponse($client, 404, OAuthPage::error('Callback route not found.'));

                return null;
            }

            parse_str((string) ($url['query'] ?? ''), $query);
            $code = is_string($query['code'] ?? null) ? $query['code'] : null;
            $state = is_string($query['state'] ?? null) ? $query['state'] : null;
            $error = is_string($query['error'] ?? null) ? $query['error'] : null;

            if ($error !== null && $error !== '') {
                $this->writeResponse($client, 400, OAuthPage::error('Authentication did not complete.', sprintf('Error: %s', $error)));

                return null;
            }

            if ($code === null || $code === '') {
                $this->writeResponse($client, 400, OAuthPage::error('Missing authorization code.'));

                return null;
            }

            if ($this->expectedState !== null && $state !== $this->expectedState) {
                $this->writeResponse($client, 400, OAuthPage::error('State mismatch.'));

                return null;
            }

            $this->writeResponse($client, 200, OAuthPage::success($this->successMessage));

            return [
                'code' => $code,
                'state' => $state,
            ];
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }
        }
    }

    private function writeResponse(mixed $client, int $status, string $body): void
    {
        $statusText = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            default => 'Error',
        };

        $response = sprintf(
            "HTTP/1.1 %d %s\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $status,
            $statusText,
            strlen($body),
            $body,
        );

        fwrite($client, $response);
    }
}
