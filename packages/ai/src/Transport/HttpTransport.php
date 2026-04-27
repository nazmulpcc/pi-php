<?php

declare(strict_types=1);

namespace Pi\AI\Transport;

use Pi\AI\CancellationToken;

final class HttpTransport
{
    public function __construct(
        private readonly ?CancellationToken $signal = null,
        private readonly ?int $timeoutMs = null,
        private readonly ?int $maxRetries = null,
        private readonly ?int $maxRetryDelayMs = null,
    ) {}

    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        $apiKey = $options['apiKey'] ?? null;
        $onResponse = $options['onResponse'] ?? null;

        $requestHeaders = $this->buildRequestHeaders($headers, $apiKey, $body !== null);

        $attempt = 0;
        $maxRetries = $this->maxRetries ?? 0;

        while (true) {
            $this->ensureNotCancelled();

            $curl = $this->initCurl($method, $url, $requestHeaders, $body);
            $responseHeaders = [];
            $status = 0;
            $responseBody = '';

            curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($_handle, string $line) use (&$status, &$responseHeaders): int {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
                    $status = (int) $matches[1];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            });

            curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($_handle, string $chunk) use (&$responseBody): int {
                $responseBody .= $chunk;

                return strlen($chunk);
            });

            $success = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);

            if ($success === false && $error !== '') {
                if ($attempt < $maxRetries && $this->isTransientCurlError($error)) {
                    $this->backoff($attempt);
                    $attempt++;

                    continue;
                }
                throw new ProviderError($error !== '' ? $error : 'Unknown cURL error');
            }

            $this->ensureNotCancelled();

            if ($onResponse !== null && is_callable($onResponse)) {
                $onResponse(['status' => $status, 'headers' => $responseHeaders]);
            }

            if ($status >= 400) {
                if ($this->isTransientHttpStatus($status) && $attempt < $maxRetries) {
                    $this->backoff($attempt);
                    $attempt++;

                    continue;
                }
                $this->throwProviderError($status, $responseBody);
            }

            return new HttpResponse($status, $responseHeaders, $responseBody);
        }
    }

    public function stream(string $method, string $url, array $options = []): iterable
    {
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        $apiKey = $options['apiKey'] ?? null;
        $onResponse = $options['onResponse'] ?? null;

        $requestHeaders = $this->buildRequestHeaders($headers, $apiKey, $body !== null);

        $attempt = 0;
        $maxRetries = $this->maxRetries ?? 0;

        while (true) {
            $this->ensureNotCancelled();

            $events = [];
            $buffer = '';
            $responseHeaders = [];
            $status = 0;

            $curl = $this->initCurl($method, $url, $requestHeaders, $body);

            curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($_handle, string $line) use (&$status, &$responseHeaders): int {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
                    $status = (int) $matches[1];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            });

            curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($_handle, string $chunk) use (&$buffer, &$events): int {
                $buffer .= $chunk;
                while (($separator = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $separator);
                    $buffer = substr($buffer, $separator + 2);
                    $event = SseParser::parseFrame($frame);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }

                return strlen($chunk);
            });

            $success = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);

            if ($buffer !== '') {
                $event = SseParser::parseFrame($buffer);
                if ($event !== null) {
                    $events[] = $event;
                }
            }

            if ($success === false && $error !== '') {
                if ($attempt < $maxRetries && $this->isTransientCurlError($error)) {
                    $this->backoff($attempt);
                    $attempt++;

                    continue;
                }
                throw new ProviderError($error !== '' ? $error : 'Unknown cURL error');
            }

            $this->ensureNotCancelled();

            if ($onResponse !== null && is_callable($onResponse)) {
                $onResponse(['status' => $status, 'headers' => $responseHeaders]);
            }

            if ($status >= 400) {
                if ($this->isTransientHttpStatus($status) && $attempt < $maxRetries) {
                    $this->backoff($attempt);
                    $attempt++;

                    continue;
                }
                $responseBody = json_encode($events);
                $this->throwProviderError($status, is_string($responseBody) ? $responseBody : '');
            }

            return $events;
        }
    }

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

        return array_map(
            static fn (string $name, string $value): string => sprintf('%s: %s', $name, $value),
            array_keys($requestHeaders),
            array_values($requestHeaders),
        );
    }

    private function initCurl(string $method, string $url, array $requestHeaders, ?array $body): \CurlHandle
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ProviderError('Unable to initialize cURL');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs ?? 0,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
            }
        }

        curl_setopt_array($curl, $opts);

        return $curl;
    }

    private function isTransientCurlError(string $error): bool
    {
        $transient = [
            'connection refused',
            'connection timed out',
            'operation timed out',
            'could not resolve host',
            'ssl connection timeout',
            'transfer closed',
            'empty reply from server',
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

    private function backoff(int $attempt): void
    {
        $base = 1000;
        $delay = $base * (2 ** $attempt);
        $jitter = random_int(0, (int) ($delay * 0.2));
        $delay += $jitter;
        $maxDelay = $this->maxRetryDelayMs ?? 60000;
        $delay = min($delay, $maxDelay);
        usleep($delay * 1000);
    }

    private function ensureNotCancelled(): void
    {
        if ($this->signal !== null && $this->signal->isCancelled()) {
            throw new ProviderError('Request was cancelled', 0, 'cancelled');
        }
    }

    private function throwProviderError(int $status, string $body): void
    {
        $message = 'HTTP '.$status;
        $type = null;
        $code = null;

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $error = $decoded['error'] ?? null;
                if (is_array($error)) {
                    $message = (string) ($error['message'] ?? $message);
                    $type = isset($error['type']) ? (string) $error['type'] : null;
                    $code = isset($error['code']) ? (string) $error['code'] : null;
                } elseif (is_string($error)) {
                    $message = $error;
                } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
                    $message = $decoded['message'];
                }
            }
        }

        throw new ProviderError($message, $status, $type, $code, $body);
    }
}
