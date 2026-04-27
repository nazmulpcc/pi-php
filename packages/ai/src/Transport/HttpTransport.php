<?php

declare(strict_types=1);

namespace Pi\AI\Transport;

use Pi\AI\CancellationToken;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Stream\ReadableStreamInterface;

use function React\Promise\resolve;

final class HttpTransport
{
    public function __construct(
        private readonly ?CancellationToken $signal = null,
        private readonly ?int $timeoutMs = null,
        private readonly ?int $maxRetries = null,
        private readonly ?int $maxRetryDelayMs = null,
    ) {}

    /**
     * @return PromiseInterface<HttpResponse>
     */
    public function request(string $method, string $url, array $options = []): PromiseInterface
    {
        return $this->withRetries(fn () => $this->doRequest($method, $url, $options));
    }

    /**
     * @return PromiseInterface<array<int, array<string, mixed>>>
     */
    public function stream(string $method, string $url, array $options = []): PromiseInterface
    {
        return $this->withRetries(fn () => $this->doStream($method, $url, $options));
    }

    /**
     * @return PromiseInterface<HttpResponse>
     */
    private function doRequest(string $method, string $url, array $options): PromiseInterface
    {
        $this->ensureNotCancelled();

        $headers = $this->buildRequestHeaders(
            $options['headers'] ?? [],
            $options['apiKey'] ?? null,
            array_key_exists('body', $options) && $options['body'] !== null,
        );
        $body = $this->encodeBody($options['body'] ?? null);
        $browser = $this->createBrowser();

        $promise = $browser->request(
            $method,
            $url,
            $headers,
            $body ?? '',
        )->then(function (ResponseInterface $response) use ($options) {
            return $this->handleBufferedResponse($response, $options['onResponse'] ?? null);
        });

        return $this->attachCancellation($promise);
    }

    /**
     * @return PromiseInterface<array<int, array<string, mixed>>>
     */
    private function doStream(string $method, string $url, array $options): PromiseInterface
    {
        $this->ensureNotCancelled();

        $headers = $this->buildRequestHeaders(
            $options['headers'] ?? [],
            $options['apiKey'] ?? null,
            array_key_exists('body', $options) && $options['body'] !== null,
        );
        $body = $this->encodeBody($options['body'] ?? null);
        $browser = $this->createBrowser();
        $onResponse = $options['onResponse'] ?? null;
        $onEvent = $options['onEvent'] ?? null;

        $promise = $browser->requestStreaming(
            $method,
            $url,
            $headers,
            $body ?? '',
        )->then(function (ResponseInterface $response) use ($onResponse, $onEvent) {
            $status = $response->getStatusCode();
            $responseHeaders = $this->normalizeResponseHeaders($response);
            $body = $response->getBody();

            if (! $body instanceof ReadableStreamInterface) {
                throw new ProviderError('Streaming response body is not readable.');
            }

            $body->pause();

            return resolve($onResponse !== null ? $onResponse([
                'status' => $status,
                'headers' => $responseHeaders,
            ]) : null)->then(function () use ($body, $status, $onEvent) {
                $deferred = new Deferred;
                $events = [];
                $rawBody = '';
                $buffer = '';

                $cancel = static function () use ($body): void {
                    $body->close();
                };

                $body->on('data', function (string $chunk) use (&$buffer, &$events, &$rawBody, $status, $onEvent): void {
                    $rawBody .= $chunk;

                    if ($status >= 400) {
                        return;
                    }

                    $buffer .= $chunk;
                    while (($separator = strpos($buffer, "\n\n")) !== false) {
                        $frame = substr($buffer, 0, $separator);
                        $buffer = substr($buffer, $separator + 2);
                        $event = SseParser::parseFrame($frame);
                        if ($event !== null) {
                            $events[] = $event;
                            if ($onEvent !== null) {
                                $onEvent($event);
                            }
                        }
                    }
                });

                $body->on('error', function (\Throwable $error) use ($deferred): void {
                    $deferred->reject($error);
                });

                $body->on('close', function () use ($deferred, &$buffer, &$events, &$rawBody, $status): void {
                    if ($buffer !== '' && $status < 400) {
                        $event = SseParser::parseFrame($buffer);
                        if ($event !== null) {
                            $events[] = $event;
                        }
                    }

                    if ($status >= 400) {
                        $deferred->reject($this->createProviderError($status, $rawBody));

                        return;
                    }

                    $deferred->resolve($events);
                });

                $body->resume();

                return $this->attachCancellation($deferred->promise(), $cancel);
            });
        });

        return $this->attachCancellation($promise);
    }

    private function createBrowser(): Browser
    {
        $connector = new Connector;
        $browser = new Browser($connector, Loop::get());
        $browser = $browser->withRejectErrorResponse(false);

        if ($this->timeoutMs !== null) {
            $browser = $browser->withTimeout($this->timeoutMs / 1000);
        }

        return $browser;
    }

    /**
     * @return PromiseInterface<HttpResponse>
     */
    private function handleBufferedResponse(ResponseInterface $response, ?callable $onResponse): PromiseInterface
    {
        $status = $response->getStatusCode();
        $headers = $this->normalizeResponseHeaders($response);
        $body = (string) $response->getBody();

        return resolve($onResponse !== null ? $onResponse([
            'status' => $status,
            'headers' => $headers,
        ]) : null)->then(function () use ($status, $headers, $body) {
            if ($status >= 400) {
                throw $this->createProviderError($status, $body);
            }

            return new HttpResponse($status, $headers, $body);
        });
    }

    /**
     * @return array<string, string>
     */
    private function buildRequestHeaders(array $headers, ?string $apiKey, bool $hasBody): array
    {
        $requestHeaders = [];
        if ($hasBody) {
            $requestHeaders['Content-Type'] = 'application/json';
        }
        if ($apiKey !== null && $apiKey !== '') {
            $requestHeaders['Authorization'] = 'Bearer '.$apiKey;
        }
        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }

        foreach ($requestHeaders as $name => $value) {
            if (preg_match('/[\r\n]/', $name) === 1 || preg_match('/[\r\n]/', $value) === 1) {
                throw new ProviderError('Invalid header: contains newline character');
            }
        }

        return $requestHeaders;
    }

    private function encodeBody(mixed $body): string|null
    {
        if ($body === null) {
            return null;
        }

        if (is_string($body)) {
            return $body;
        }

        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeResponseHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * @return PromiseInterface<mixed>
     */
    private function withRetries(callable $operation, int $attempt = 0): PromiseInterface
    {
        return resolve(null)
            ->then(fn () => $operation())
            ->then(
                null,
                function (mixed $error) use ($operation, $attempt) {
                    $throwable = $this->normalizeTransportError($error);
                    $maxRetries = $this->maxRetries ?? 0;

                    if ($attempt >= $maxRetries || ! $this->isRetryable($throwable)) {
                        throw $throwable;
                    }

                    return $this->sleep($this->retryDelayMs($attempt))
                        ->then(fn () => $this->withRetries($operation, $attempt + 1));
                },
            );
    }

    /**
     * @return PromiseInterface<mixed>
     */
    private function attachCancellation(PromiseInterface $promise, ?callable $cancel = null): PromiseInterface
    {
        if ($this->signal === null) {
            return $promise;
        }

        $deferred = new Deferred;
        $settled = false;
        $timer = Loop::addPeriodicTimer(0.05, function (TimerInterface $timer) use (&$settled, $deferred, $cancel): void {
            if (! $settled && $this->signal?->isCancelled()) {
                $settled = true;
                Loop::cancelTimer($timer);
                if ($cancel !== null) {
                    $cancel();
                }
                $deferred->reject(new ProviderError('Request was cancelled', 0, 'aborted'));
            }
        });

        $promise->then(
            function (mixed $value) use ($deferred, &$settled, $timer): void {
                if ($settled) {
                    return;
                }
                $settled = true;
                Loop::cancelTimer($timer);
                $deferred->resolve($value);
            },
            function (mixed $error) use ($deferred, &$settled, $timer): void {
                if ($settled) {
                    return;
                }
                $settled = true;
                Loop::cancelTimer($timer);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * @return PromiseInterface<void>
     */
    private function sleep(int $delayMs): PromiseInterface
    {
        $deferred = new Deferred;
        Loop::addTimer($delayMs / 1000, static function () use ($deferred): void {
            $deferred->resolve(null);
        });

        return $deferred->promise();
    }

    private function retryDelayMs(int $attempt): int
    {
        $base = 1000;
        $delay = $base * (2 ** $attempt);
        $jitter = random_int(0, (int) ($delay * 0.2));
        $maxDelay = $this->maxRetryDelayMs ?? 60000;

        return min($delay + $jitter, $maxDelay);
    }

    private function isRetryable(\Throwable $error): bool
    {
        if ($error instanceof ProviderError) {
            return $this->isTransientHttpStatus($error->status) || $this->isTransientTransportError($error->getMessage());
        }

        if ($error instanceof ResponseException) {
            return $this->isTransientHttpStatus($error->getCode());
        }

        return $this->isTransientTransportError($error->getMessage());
    }

    private function isTransientTransportError(string $error): bool
    {
        $transient = [
            'connection refused',
            'connection timed out',
            'operation timed out',
            'could not resolve host',
            'dns query failed',
            'empty reply from server',
            'temporarily unavailable',
            'timed out',
            'connection reset',
            'closed',
        ];
        $lower = strtolower($error);
        foreach ($transient as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isTransientHttpStatus(int $status): bool
    {
        return $status >= 500 || $status === 429;
    }

    private function ensureNotCancelled(): void
    {
        if ($this->signal?->isCancelled()) {
            throw new ProviderError('Request was cancelled', 0, 'aborted');
        }
    }

    private function normalizeTransportError(mixed $error): \Throwable
    {
        if ($error instanceof ProviderError) {
            return $error;
        }

        if ($error instanceof ResponseException) {
            $status = $error->getCode();
            $body = (string) $error->getResponse()->getBody();

            return $this->createProviderError($status, $body, $error);
        }

        if ($error instanceof \Throwable) {
            return $error;
        }

        return new ProviderError('Unknown transport error');
    }

    private function createProviderError(int $status, string $body, ?\Throwable $previous = null): ProviderError
    {
        $rawBody = strlen($body) > 4096 ? substr($body, 0, 4096).'…[truncated]' : $body;
        $message = $this->extractErrorMessage($status, $rawBody);

        return new ProviderError(
            $message,
            status: $status,
            rawBody: $rawBody,
            previous: $previous,
        );
    }

    private function extractErrorMessage(int $status, string $body): string
    {
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $message = $decoded['error']['message']
                    ?? $decoded['message']
                    ?? $decoded['error']['error']['message']
                    ?? null;

                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }

            return sprintf('HTTP %d: %s', $status, $body);
        }

        return sprintf('HTTP %d', $status);
    }
}
