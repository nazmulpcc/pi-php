<?php

declare(strict_types=1);

namespace Pi\AI\OpenAI\Codex;

use Evenement\EventEmitterInterface;
use Pi\AI\CancellationToken;
use Ratchet\Client\Connector as RatchetConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Connector as ReactConnector;

use function React\Promise\reject;
use function React\Promise\resolve;

final class CodexWebSocketTransport
{
    private const SESSION_CACHE_TTL_SECONDS = 300;

    /** @var array<string, array{socket: object, busy: bool, idleTimer: ?TimerInterface, listeners: array<string, callable>, closed: bool}> */
    private static array $sessionCache = [];

    /** @var null|callable(string, array<string, string>): PromiseInterface<object> */
    private static $connectorFactory = null;

    /**
     * @param  callable(array<string, mixed>): PromiseInterface<mixed>|mixed  $onEvent
     * @param  callable(): void  $onStart
     */
    public function stream(
        string $url,
        array $headers,
        array $body,
        ?string $sessionId,
        ?CancellationToken $signal,
        callable $onStart,
        callable $onEvent,
    ): PromiseInterface {
        $started = false;

        return $this->acquire($url, $headers, $sessionId, $signal)
            ->then(function (array $acquired) use ($body, $signal, $onStart, $onEvent, &$started) {
                $socket = $acquired['socket'];
                $release = $acquired['release'];
                $keepConnection = true;

                try {
                    $this->send($socket, json_encode(['type' => 'response.create', ...$body], JSON_THROW_ON_ERROR));
                    $started = true;
                    $onStart();

                    return $this->processMessages($socket, $signal, $onEvent)
                        ->then(
                            function () use ($release, &$keepConnection): void {
                                $release(['keep' => $keepConnection]);
                            },
                            function (mixed $error) use ($release, &$keepConnection) {
                                $keepConnection = false;
                                $release(['keep' => false]);
                                throw $error instanceof \Throwable ? $error : new \RuntimeException((string) $error);
                            },
                        );
                } catch (\Throwable $error) {
                    $keepConnection = false;
                    $release(['keep' => false]);

                    return reject(new CodexWebSocketTransportException($error->getMessage(), $error, $started));
                }
            })
            ->then(
                null,
                fn (mixed $error) => reject(
                    $error instanceof CodexWebSocketTransportException
                        ? $error
                        : new CodexWebSocketTransportException(
                            $error instanceof \Throwable ? $error->getMessage() : (string) $error,
                            $error instanceof \Throwable ? $error : null,
                            $started,
                        )
                ),
            );
    }

    /**
     * @param  null|callable(string, array<string, string>): PromiseInterface<object>  $factory
     */
    public static function setConnectorFactoryForTests(?callable $factory): void
    {
        self::$connectorFactory = $factory;
        self::$sessionCache = [];
    }

    /**
     * @return PromiseInterface<array{socket: object, release: callable(array{keep?: bool}): void}>
     */
    private function acquire(string $url, array $headers, ?string $sessionId, ?CancellationToken $signal): PromiseInterface
    {
        if ($sessionId === null || $sessionId === '') {
            return $this->connect($url, $headers, $signal)->then(function (object $socket): array {
                return [
                    'socket' => $socket,
                    'release' => function (array $options = []) use ($socket): void {
                        $this->closeSilently($socket);
                    },
                ];
            });
        }

        $cached = self::$sessionCache[$sessionId] ?? null;
        if ($cached !== null) {
            if ($cached['idleTimer'] instanceof TimerInterface) {
                Loop::cancelTimer($cached['idleTimer']);
                $cached['idleTimer'] = null;
            }

            if (! $cached['busy'] && ! $cached['closed']) {
                $cached['busy'] = true;
                self::$sessionCache[$sessionId] = $cached;

                return resolve([
                    'socket' => $cached['socket'],
                    'release' => function (array $options = []) use ($sessionId): void {
                        $keep = $options['keep'] ?? true;
                        $current = self::$sessionCache[$sessionId] ?? null;
                        if ($current === null) {
                            return;
                        }

                        if (! $keep || $current['closed']) {
                            $this->closeSilently($current['socket']);
                            unset(self::$sessionCache[$sessionId]);

                            return;
                        }

                        $current['busy'] = false;
                        $current['idleTimer'] = Loop::addTimer(self::SESSION_CACHE_TTL_SECONDS, function () use ($sessionId): void {
                            $entry = self::$sessionCache[$sessionId] ?? null;
                            if ($entry === null || $entry['busy']) {
                                return;
                            }

                            $this->closeSilently($entry['socket']);
                            unset(self::$sessionCache[$sessionId]);
                        });
                        self::$sessionCache[$sessionId] = $current;
                    },
                ]);
            }
        }

        return $this->connect($url, $headers, $signal)->then(function (object $socket) use ($sessionId): array {
            $entry = [
                'socket' => $socket,
                'busy' => true,
                'idleTimer' => null,
                'listeners' => [],
                'closed' => false,
            ];
            self::$sessionCache[$sessionId] = $entry;

            return [
                'socket' => $socket,
                'release' => function (array $options = []) use ($sessionId): void {
                    $keep = $options['keep'] ?? true;
                    $entry = self::$sessionCache[$sessionId] ?? null;
                    if ($entry === null) {
                        return;
                    }

                    if (! $keep || $entry['closed']) {
                        $this->closeSilently($entry['socket']);
                        unset(self::$sessionCache[$sessionId]);

                        return;
                    }

                    $entry['busy'] = false;
                    $entry['idleTimer'] = Loop::addTimer(self::SESSION_CACHE_TTL_SECONDS, function () use ($sessionId): void {
                        $current = self::$sessionCache[$sessionId] ?? null;
                        if ($current === null || $current['busy']) {
                            return;
                        }

                        $this->closeSilently($current['socket']);
                        unset(self::$sessionCache[$sessionId]);
                    });
                    self::$sessionCache[$sessionId] = $entry;
                },
            ];
        });
    }

    /**
     * @return PromiseInterface<object>
     */
    private function connect(string $url, array $headers, ?CancellationToken $signal): PromiseInterface
    {
        if ($signal?->isCancelled()) {
            return reject(new \RuntimeException('Request was aborted'));
        }

        $factory = self::$connectorFactory;
        if ($factory !== null) {
            return $factory($url, $headers);
        }

        $connector = new RatchetConnector(Loop::get(), new ReactConnector);

        /** @var PromiseInterface<object> $promise */
        $promise = $connector($url, [], $headers)->then(static fn (WebSocket $socket): object => $socket);

        if ($signal === null) {
            return $promise;
        }

        $deferred = new Deferred;
        $settled = false;
        $timer = Loop::addPeriodicTimer(0.05, function (TimerInterface $timer) use ($signal, &$settled, $deferred): void {
            if ($settled) {
                Loop::cancelTimer($timer);

                return;
            }

            if (! $signal->isCancelled()) {
                return;
            }

            $settled = true;
            Loop::cancelTimer($timer);
            $deferred->reject(new \RuntimeException('Request was aborted'));
        });

        $promise->then(
            function (object $socket) use (&$settled, $deferred, $timer, $signal): void {
                if ($settled) {
                    $this->closeSilently($socket);

                    return;
                }

                if ($signal?->isCancelled()) {
                    $settled = true;
                    Loop::cancelTimer($timer);
                    $this->closeSilently($socket);
                    $deferred->reject(new \RuntimeException('Request was aborted'));

                    return;
                }

                $settled = true;
                Loop::cancelTimer($timer);
                $deferred->resolve($socket);
            },
            function (mixed $error) use (&$settled, $deferred, $timer): void {
                if ($settled) {
                    return;
                }

                $settled = true;
                Loop::cancelTimer($timer);
                $deferred->reject($error instanceof \Throwable ? $error : new \RuntimeException((string) $error));
            },
        );

        return $deferred->promise();
    }

    /**
     * @param  callable(array<string, mixed>): PromiseInterface<mixed>|mixed  $onEvent
     */
    private function processMessages(object $socket, ?CancellationToken $signal, callable $onEvent): PromiseInterface
    {
        $deferred = new Deferred;
        $queue = [];
        $processing = false;
        $done = false;
        $sawCompletion = false;
        $closed = false;
        $abortTimer = null;

        $messageListener = function (mixed $message) use (&$queue, &$processing, &$done, &$sawCompletion, $onEvent, $deferred, $socket, &$closed, &$messageListener, &$closeListener, &$errorListener, &$abortTimer): void {
            $text = $this->decodeMessage($message);
            if ($text === null) {
                return;
            }

            $decoded = json_decode($text, true);
            if (! is_array($decoded)) {
                return;
            }

            $type = $decoded['type'] ?? null;
            if ($type === 'response.completed' || $type === 'response.done' || $type === 'response.incomplete') {
                $sawCompletion = true;
                $done = true;
            }

            $queue[] = $decoded;
            $this->drainQueue($queue, $processing, $done, $sawCompletion, $onEvent, $deferred, function () use (&$closed, $socket, &$messageListener, &$closeListener, &$errorListener, &$abortTimer): void {
                if ($closed) {
                    return;
                }
                $closed = true;
                if ($abortTimer instanceof TimerInterface) {
                    Loop::cancelTimer($abortTimer);
                }
                $this->removeSocketListener($socket, 'message', $messageListener);
                $this->removeSocketListener($socket, 'close', $closeListener);
                $this->removeSocketListener($socket, 'error', $errorListener);
            });
        };

        $closeListener = function (mixed $code = null, mixed $reason = null) use (&$done, &$sawCompletion, $deferred, &$closed, $socket, &$messageListener, &$closeListener, &$errorListener, &$abortTimer): void {
            if ($closed) {
                return;
            }

            $closed = true;
            if ($abortTimer instanceof TimerInterface) {
                Loop::cancelTimer($abortTimer);
            }
            $this->removeSocketListener($socket, 'message', $messageListener);
            $this->removeSocketListener($socket, 'close', $closeListener);
            $this->removeSocketListener($socket, 'error', $errorListener);

            if ($sawCompletion) {
                $done = true;
                $deferred->resolve(null);

                return;
            }

            $message = 'WebSocket closed';
            if (is_scalar($code) && $code !== '') {
                $message .= ' '.(string) $code;
            }
            if (is_scalar($reason) && $reason !== '') {
                $message .= ' '.(string) $reason;
            }

            $deferred->reject(new \RuntimeException(trim($message)));
        };

        $errorListener = function (mixed $error) use ($deferred, &$closed, $socket, &$messageListener, &$closeListener, &$errorListener, &$abortTimer): void {
            if ($closed) {
                return;
            }

            $closed = true;
            if ($abortTimer instanceof TimerInterface) {
                Loop::cancelTimer($abortTimer);
            }
            $this->removeSocketListener($socket, 'message', $messageListener);
            $this->removeSocketListener($socket, 'close', $closeListener);
            $this->removeSocketListener($socket, 'error', $errorListener);
            $deferred->reject($error instanceof \Throwable ? $error : new \RuntimeException('WebSocket error'));
        };

        $this->addSocketListener($socket, 'message', $messageListener);
        $this->addSocketListener($socket, 'close', $closeListener);
        $this->addSocketListener($socket, 'error', $errorListener);

        if ($signal !== null) {
            $abortTimer = Loop::addPeriodicTimer(0.05, function (TimerInterface $timer) use ($signal, &$closed, $socket, &$messageListener, &$closeListener, &$errorListener, $deferred): void {
                if ($closed) {
                    Loop::cancelTimer($timer);

                    return;
                }

                if (! $signal->isCancelled()) {
                    return;
                }

                $closed = true;
                Loop::cancelTimer($timer);
                $this->removeSocketListener($socket, 'message', $messageListener);
                $this->removeSocketListener($socket, 'close', $closeListener);
                $this->removeSocketListener($socket, 'error', $errorListener);
                $this->closeSilently($socket);
                $deferred->reject(new \RuntimeException('Request was aborted'));
            });
        }

        return $deferred->promise();
    }

    /**
     * @param  array<int, array<string, mixed>>  $queue
     * @param  callable(array<string, mixed>): PromiseInterface<mixed>|mixed  $onEvent
     * @param  callable(): void  $cleanup
     */
    private function drainQueue(array &$queue, bool &$processing, bool &$done, bool &$sawCompletion, callable $onEvent, Deferred $deferred, callable $cleanup): void
    {
        if ($processing) {
            return;
        }

        if ($queue === []) {
            if ($done && $sawCompletion) {
                $cleanup();
                $deferred->resolve(null);
            }

            return;
        }

        $processing = true;
        $event = array_shift($queue);

        resolve($onEvent($event))->then(
            function () use (&$processing, &$queue, &$done, &$sawCompletion, $onEvent, $deferred, $cleanup): void {
                $processing = false;
                $this->drainQueue($queue, $processing, $done, $sawCompletion, $onEvent, $deferred, $cleanup);
            },
            function (mixed $error) use (&$processing, $deferred, $cleanup): void {
                $processing = false;
                $cleanup();
                $deferred->reject($error instanceof \Throwable ? $error : new \RuntimeException((string) $error));
            },
        );
    }

    private function decodeMessage(mixed $message): ?string
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_object($message)) {
            if (method_exists($message, 'getPayload')) {
                $payload = $message->getPayload();

                return is_string($payload) ? $payload : null;
            }

            if (property_exists($message, 'data') && is_string($message->data)) {
                return $message->data;
            }
        }

        return null;
    }

    private function send(object $socket, string $payload): void
    {
        if (! method_exists($socket, 'send')) {
            throw new \RuntimeException('WebSocket transport does not support send().');
        }

        $socket->send($payload);
    }

    private function closeSilently(object $socket): void
    {
        if (! method_exists($socket, 'close')) {
            return;
        }

        try {
            $socket->close();
        } catch (\Throwable) {
        }
    }

    private function addSocketListener(object $socket, string $event, callable $listener): void
    {
        if (! $socket instanceof EventEmitterInterface) {
            throw new \RuntimeException('WebSocket transport does not support event listeners.');
        }

        $socket->on($event, $listener);
    }

    private function removeSocketListener(object $socket, string $event, callable $listener): void
    {
        if (! $socket instanceof EventEmitterInterface) {
            return;
        }

        if (method_exists($socket, 'removeListener')) {
            $socket->removeListener($event, $listener);
        }
    }
}
