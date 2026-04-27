<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Session;

use Pi\Agent\AgentMessage;
use Pi\Agent\Content\TextContent;
use Pi\Agent\Message\UserMessage;
use Pi\Agent\ThinkingLevel;
use Pi\AI\Model;

use function Pi\AI\getModel;

final class SessionManager
{
    private const CURRENT_VERSION = 1;

    /** @var array<int, array<string, mixed>> */
    private array $entries = [];

    private string $sessionId;

    private ?string $sessionFile = null;

    private bool $flushed = false;

    private ?string $leafId = null;

    private function __construct(
        private string $cwd,
        private ?string $sessionDir,
        private bool $persist,
        ?string $sessionFile = null,
        ?string $sessionId = null,
    ) {
        $this->sessionId = $sessionId ?? self::createSessionId();

        if ($this->persist && $this->sessionDir !== null && ! is_dir($this->sessionDir)) {
            mkdir($this->sessionDir, 0777, true);
        }

        if ($sessionFile !== null) {
            $this->setSessionFile($sessionFile);
        } else {
            $this->newSession($sessionId);
        }
    }

    public static function create(string $cwd, ?string $sessionDir = null, bool $persist = true, ?string $sessionId = null): self
    {
        return new self($cwd, $sessionDir, $persist, null, $sessionId);
    }

    public static function open(string $sessionFile, ?string $sessionDir = null, ?string $cwdOverride = null): self
    {
        return new self($cwdOverride ?? getcwd() ?: '.', $sessionDir ?? dirname($sessionFile), true, $sessionFile);
    }

    public static function continueRecent(string $cwd, ?string $sessionDir = null): ?self
    {
        $dir = $sessionDir ?? $cwd.'/.pi/sessions';
        $files = glob(rtrim($dir, '/').'/*.jsonl');
        if ($files === false || $files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return self::open($files[0], $dir);
    }

    public function newSession(?string $sessionId = null, ?string $parentSession = null): ?string
    {
        $this->sessionId = $sessionId ?? self::createSessionId();
        $timestamp = gmdate('c');
        $this->entries = [[
            'type' => 'session',
            'version' => self::CURRENT_VERSION,
            'id' => $this->sessionId,
            'timestamp' => $timestamp,
            'cwd' => $this->cwd,
            'parentSession' => $parentSession,
        ]];
        $this->leafId = null;
        $this->flushed = false;

        if ($this->persist && $this->sessionDir !== null) {
            $fileTimestamp = preg_replace('/[:.]/', '-', $timestamp);
            $this->sessionFile = rtrim($this->sessionDir, '/').'/'.$fileTimestamp.'_'.$this->sessionId.'.jsonl';
        }

        return $this->sessionFile;
    }

    public function setSessionFile(string $sessionFile): void
    {
        $this->sessionFile = $sessionFile;

        if (is_file($sessionFile)) {
            $this->entries = $this->loadEntriesFromFile($sessionFile);
            if ($this->entries === []) {
                $explicitPath = $this->sessionFile;
                $this->newSession();
                $this->sessionFile = $explicitPath;
                $this->rewriteFile();
                $this->flushed = true;

                return;
            }

            $header = $this->getHeader();
            $this->sessionId = (string) ($header['id'] ?? self::createSessionId());
            $this->cwd = (string) ($header['cwd'] ?? $this->cwd);
            $this->rebuildLeaf();
            $this->flushed = true;

            return;
        }

        $explicitPath = $this->sessionFile;
        $this->newSession();
        $this->sessionFile = $explicitPath;
    }

    public function getHeader(): ?array
    {
        $header = $this->entries[0] ?? null;

        return is_array($header) && ($header['type'] ?? null) === 'session' ? $header : null;
    }

    public function getCwd(): string
    {
        return $this->cwd;
    }

    public function getSessionDir(): ?string
    {
        return $this->sessionDir;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getSessionFile(): ?string
    {
        return $this->sessionFile;
    }

    public function getLastTimestamp(): string
    {
        $entry = $this->entries[count($this->entries) - 1] ?? null;

        return (string) ($entry['timestamp'] ?? '');
    }

    public function isPersisted(): bool
    {
        return $this->persist;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function appendMessage(AgentMessage $message): string
    {
        return $this->appendEntry([
            'type' => 'message',
            'message' => MessageSerializer::serializeMessage($message),
        ]);
    }

    public function appendThinkingLevelChange(ThinkingLevel $thinkingLevel): string
    {
        return $this->appendEntry([
            'type' => 'thinking_level_change',
            'thinkingLevel' => $thinkingLevel->value,
        ]);
    }

    public function appendModelChange(Model $model): string
    {
        return $this->appendEntry([
            'type' => 'model_change',
            'provider' => $model->provider->value,
            'modelId' => $model->id,
        ]);
    }

    public function appendCompaction(string $summary, string $firstKeptEntryId, int $tokensBefore): string
    {
        return $this->appendEntry([
            'type' => 'compaction',
            'summary' => $summary,
            'firstKeptEntryId' => $firstKeptEntryId,
            'tokensBefore' => $tokensBefore,
        ]);
    }

    public function appendSessionInfo(?string $name): string
    {
        return $this->appendEntry([
            'type' => 'session_info',
            'name' => $name,
        ]);
    }

    /**
     * @return array{messages: array<int, AgentMessage>, thinkingLevel: ThinkingLevel, model: ?Model}
     */
    public function buildSessionContext(): array
    {
        $messages = [];
        $thinkingLevel = ThinkingLevel::Medium;
        $model = null;
        $latestCompaction = null;

        foreach ($this->entries as $entry) {
            if (($entry['type'] ?? null) === 'thinking_level_change') {
                $thinkingLevel = ThinkingLevel::from((string) $entry['thinkingLevel']);
            } elseif (($entry['type'] ?? null) === 'model_change') {
                $model = getModel((string) $entry['provider'], (string) $entry['modelId']);
            } elseif (($entry['type'] ?? null) === 'compaction') {
                $latestCompaction = $entry;
            }
        }

        if (is_array($latestCompaction)) {
            $messages[] = new UserMessage(
                content: [new TextContent("Compacted conversation summary:\n".(string) $latestCompaction['summary'])],
                timestamp: self::timestampToMillis((string) $latestCompaction['timestamp']),
            );
        }

        $collect = $latestCompaction === null;
        $firstKeptEntryId = (string) ($latestCompaction['firstKeptEntryId'] ?? '');

        foreach ($this->entries as $entry) {
            if (($entry['type'] ?? null) !== 'message') {
                continue;
            }

            if (! $collect && ($entry['id'] ?? null) === $firstKeptEntryId) {
                $collect = true;
            }

            if (! $collect) {
                continue;
            }

            $messages[] = MessageSerializer::hydrateMessage($entry['message']);
        }

        return [
            'messages' => $messages,
            'thinkingLevel' => $thinkingLevel,
            'model' => $model,
        ];
    }

    public function reload(): void
    {
        if (! $this->persist || $this->sessionFile === null || ! is_file($this->sessionFile)) {
            return;
        }

        $this->entries = $this->loadEntriesFromFile($this->sessionFile);
        $header = $this->getHeader();
        if ($header !== null) {
            $this->sessionId = (string) $header['id'];
            $this->cwd = (string) $header['cwd'];
        }
        $this->rebuildLeaf();
        $this->flushed = true;
    }

    private function appendEntry(array $data): string
    {
        $entry = array_merge($data, [
            'id' => self::createEntryId(),
            'parentId' => $this->leafId,
            'timestamp' => gmdate('c'),
        ]);
        $this->entries[] = $entry;
        $this->leafId = $entry['id'];
        $this->persistEntry($entry);

        return $entry['id'];
    }

    private function persistEntry(array $entry): void
    {
        if (! $this->persist || $this->sessionFile === null) {
            return;
        }

        $hasAssistant = false;
        foreach ($this->entries as $candidate) {
            if (($candidate['type'] ?? null) === 'message' && (($candidate['message']['role'] ?? null) === 'assistant')) {
                $hasAssistant = true;
                break;
            }
        }

        if (! $hasAssistant) {
            $this->flushed = false;

            return;
        }

        if (! $this->flushed) {
            $this->rewriteFile();
            $this->flushed = true;

            return;
        }

        file_put_contents($this->sessionFile, json_encode($entry, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }

    private function rewriteFile(): void
    {
        if (! $this->persist || $this->sessionFile === null) {
            return;
        }

        $lines = array_map(
            static fn (array $entry): string => json_encode($entry, JSON_THROW_ON_ERROR),
            $this->entries,
        );
        file_put_contents($this->sessionFile, implode("\n", $lines)."\n");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadEntriesFromFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    private function rebuildLeaf(): void
    {
        $this->leafId = null;
        foreach ($this->entries as $entry) {
            if (($entry['type'] ?? null) === 'session') {
                continue;
            }

            $this->leafId = (string) $entry['id'];
        }
    }

    private static function createSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function createEntryId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 8);
    }

    private static function timestampToMillis(string $timestamp): int
    {
        $seconds = strtotime($timestamp);

        return $seconds === false ? (int) (microtime(true) * 1000) : $seconds * 1000;
    }
}
