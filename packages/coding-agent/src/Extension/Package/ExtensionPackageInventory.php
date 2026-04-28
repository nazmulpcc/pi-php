<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final readonly class ExtensionPackageInventory
{
    /**
     * @param  list<ExtensionPackageRecord>  $packages
     */
    public function __construct(
        public array $packages = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'packages' => array_map(
                static fn (ExtensionPackageRecord $record): array => $record->toArray(),
                $this->packages,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $packages = [];
        foreach (($data['packages'] ?? []) as $record) {
            if (! is_array($record)) {
                throw new \InvalidArgumentException('Extension package inventory entries must be objects.');
            }
            $packages[] = ExtensionPackageRecord::fromArray($record);
        }

        return new self($packages);
    }
}
