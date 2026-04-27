<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

use Pi\Agent\ThinkingLevel;
use Pi\AI\Model;

final readonly class FilesystemSessionStore implements SessionStore
{
    public function __construct(
        public string $directory,
    ) {}

    public function createSnapshot(
        string $cwd,
        ?Model $model,
        string $systemPrompt,
        ThinkingLevel $thinkingLevel,
        array $messages,
        ?string $sessionId = null,
    ): SessionSnapshot {
        $now = (int) (microtime(true) * 1000);
        $sessionId ??= bin2hex(random_bytes(16));

        return new SessionSnapshot(
            sessionId: $sessionId,
            cwd: $cwd,
            model: $model,
            systemPrompt: $systemPrompt,
            thinkingLevel: $thinkingLevel,
            messages: $messages,
            createdAt: $now,
            updatedAt: $now,
            path: $this->pathFor($sessionId),
        );
    }

    public function save(SessionSnapshot $snapshot): SessionSnapshot
    {
        if (! is_dir($this->directory) && ! mkdir($concurrentDirectory = $this->directory, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create session directory: %s', $this->directory));
        }

        $path = $snapshot->path ?? $this->pathFor($snapshot->sessionId);
        $updated = new SessionSnapshot(
            sessionId: $snapshot->sessionId,
            cwd: $snapshot->cwd,
            model: $snapshot->model,
            systemPrompt: $snapshot->systemPrompt,
            thinkingLevel: $snapshot->thinkingLevel,
            messages: $snapshot->messages,
            createdAt: $snapshot->createdAt,
            updatedAt: (int) (microtime(true) * 1000),
            path: $path,
        );

        file_put_contents($path, json_encode(MessageSerializer::serializeSnapshot($updated), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $updated;
    }

    public function load(string $sessionIdOrPath): ?SessionSnapshot
    {
        $path = str_contains($sessionIdOrPath, DIRECTORY_SEPARATOR) || str_ends_with($sessionIdOrPath, '.json')
            ? $sessionIdOrPath
            : $this->resolveIdPath($sessionIdOrPath);

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return MessageSerializer::hydrateSnapshot($data, $path);
    }

    public function loadLatest(): ?SessionSnapshot
    {
        $files = glob($this->directory.'/*.json');
        if ($files === false || $files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $this->load($files[0]);
    }

    private function resolveIdPath(string $sessionIdOrPrefix): ?string
    {
        $exact = $this->pathFor($sessionIdOrPrefix);
        if (is_file($exact)) {
            return $exact;
        }

        $matches = glob($this->directory.'/'.$sessionIdOrPrefix.'*.json');
        if ($matches === false || $matches === []) {
            return null;
        }

        sort($matches);

        return $matches[0];
    }

    private function pathFor(string $sessionId): string
    {
        return rtrim($this->directory, '/').'/'.$sessionId.'.json';
    }
}
