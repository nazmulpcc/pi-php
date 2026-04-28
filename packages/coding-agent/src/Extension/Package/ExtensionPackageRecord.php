<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final readonly class ExtensionPackageRecord
{
    /**
     * @param  list<string>  $extensions
     * @param  list<string>  $skills
     * @param  list<string>  $prompts
     * @param  list<string>  $themes
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $scope,
        public string $sourceType,
        public string $source,
        public string $installedPath,
        public bool $enabled,
        public bool $managed,
        public ?string $versionOrRef,
        public array $extensions = [],
        public array $skills = [],
        public array $prompts = [],
        public array $themes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::requireString($data, 'id'),
            name: self::requireString($data, 'name'),
            scope: ExtensionPackageScope::assertValid(self::requireString($data, 'scope')),
            sourceType: ExtensionPackageSourceType::assertValid(self::requireString($data, 'sourceType')),
            source: self::requireString($data, 'source'),
            installedPath: self::requireString($data, 'installedPath'),
            enabled: (bool) ($data['enabled'] ?? true),
            managed: (bool) ($data['managed'] ?? true),
            versionOrRef: isset($data['versionOrRef']) && is_string($data['versionOrRef']) && $data['versionOrRef'] !== ''
                ? $data['versionOrRef']
                : null,
            extensions: self::filterStringList($data['extensions'] ?? []),
            skills: self::filterStringList($data['skills'] ?? []),
            prompts: self::filterStringList($data['prompts'] ?? []),
            themes: self::filterStringList($data['themes'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope' => $this->scope,
            'sourceType' => $this->sourceType,
            'source' => $this->source,
            'installedPath' => $this->installedPath,
            'enabled' => $this->enabled,
            'managed' => $this->managed,
            'versionOrRef' => $this->versionOrRef,
            'extensions' => array_values($this->extensions),
            'skills' => array_values($this->skills),
            'prompts' => array_values($this->prompts),
            'themes' => array_values($this->themes),
        ];
    }

    /**
     * @return list<string>
     */
    private static function filterStringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('Extension package record field "%s" must be a non-empty string.', $key));
        }

        return $value;
    }
}
