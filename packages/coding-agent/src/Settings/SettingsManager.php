<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Settings;

use Pi\Agent\ThinkingLevel;
use Pi\CodingAgent\Config;

final class SettingsManager
{
    /** @var array<string, mixed> */
    private array $globalSettings = [];

    /** @var array<string, mixed> */
    private array $projectSettings = [];

    /** @var list<array{scope:string,error:string}> */
    private array $errors = [];

    private function __construct(
        private readonly SettingsStorage $storage,
    ) {
        $this->reload();
    }

    public static function create(string $cwd, ?string $agentDir = null): self
    {
        return new self(new FileSettingsStorage($cwd, $agentDir ?? Config::getAgentDir()));
    }

    /**
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $project
     */
    public static function inMemory(array $global = [], array $project = []): self
    {
        $storage = new InMemorySettingsStorage;
        $storage->write('global', json_encode($global, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $storage->write('project', json_encode($project, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return new self($storage);
    }

    public function reload(): void
    {
        $this->errors = [];
        $this->globalSettings = $this->loadScope('global');
        $this->projectSettings = $this->loadScope('project');
    }

    /**
     * @return list<array{scope:string,error:string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return self::deepMerge($this->globalSettings, $this->projectSettings);
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobalSettings(): array
    {
        return $this->globalSettings;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProjectSettings(): array
    {
        return $this->projectSettings;
    }

    public function getDefaultProvider(): ?string
    {
        $value = $this->getSettings()['defaultProvider'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getDefaultModel(): ?string
    {
        $value = $this->getSettings()['defaultModel'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getDefaultThinkingLevel(): ?ThinkingLevel
    {
        $value = $this->getSettings()['defaultThinkingLevel'] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return ThinkingLevel::from($value);
    }

    public function getSteeringMode(): string
    {
        $value = $this->getSettings()['steeringMode'] ?? 'one-at-a-time';

        return $value === 'all' ? 'all' : 'one-at-a-time';
    }

    public function getFollowUpMode(): string
    {
        $value = $this->getSettings()['followUpMode'] ?? 'one-at-a-time';

        return $value === 'all' ? 'all' : 'one-at-a-time';
    }

    public function getSessionDir(string $cwd): string
    {
        $value = $this->getSettings()['sessionDir'] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return rtrim($cwd, '/').'/'.Config::CONFIG_DIR_NAME.'/sessions';
    }

    public function getCompactionEnabled(): bool
    {
        return (bool) (($this->getSettings()['compaction']['enabled'] ?? true));
    }

    public function getCompactionReserveTokens(): int
    {
        return (int) (($this->getSettings()['compaction']['reserveTokens'] ?? 16384));
    }

    public function getCompactionKeepRecentTokens(): int
    {
        return (int) (($this->getSettings()['compaction']['keepRecentTokens'] ?? 20000));
    }

    public function getRetryEnabled(): bool
    {
        return (bool) (($this->getSettings()['retry']['enabled'] ?? true));
    }

    public function getRetryMaxRetries(): int
    {
        return (int) (($this->getSettings()['retry']['maxRetries'] ?? 3));
    }

    public function getRetryBaseDelayMs(): int
    {
        return (int) (($this->getSettings()['retry']['baseDelayMs'] ?? 2000));
    }

    /**
     * @return list<string>
     */
    public function getSkillPaths(): array
    {
        $value = $this->getSettings()['skills'] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @return list<string>
     */
    public function getPromptPaths(): array
    {
        $value = $this->getSettings()['prompts'] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function setGlobalSettings(array $settings): void
    {
        $this->globalSettings = $settings;
        $this->storage->write('global', json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function setProjectSettings(array $settings): void
    {
        $this->projectSettings = $settings;
        $this->storage->write('project', json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function setValue(string $scope, string $key, mixed $value): void
    {
        $segments = array_values(array_filter(explode('.', $key), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            throw new \RuntimeException('Setting key must not be empty.');
        }

        $settings = $scope === 'global' ? $this->globalSettings : $this->projectSettings;
        $cursor = &$settings;

        foreach ($segments as $index => $segment) {
            $last = $index === count($segments) - 1;
            if ($last) {
                $cursor[$segment] = $value;
                break;
            }

            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        if ($scope === 'global') {
            $this->setGlobalSettings($settings);

            return;
        }

        $this->setProjectSettings($settings);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadScope(string $scope): array
    {
        $content = $this->storage->read($scope);
        if ($content === null || trim($content) === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $error) {
            $this->errors[] = ['scope' => $scope, 'error' => $error->getMessage()];

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $overrides): array
    {
        $result = $base;
        foreach ($overrides as $key => $value) {
            if (
                isset($result[$key]) &&
                is_array($result[$key]) &&
                is_array($value) &&
                array_is_list($result[$key]) === false &&
                array_is_list($value) === false
            ) {
                $result[$key] = self::deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
