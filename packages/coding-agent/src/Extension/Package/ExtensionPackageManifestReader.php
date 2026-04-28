<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final class ExtensionPackageManifestReader
{
    public function read(string $path): ExtensionPackageManifest
    {
        if (is_file($path)) {
            return new ExtensionPackageManifest(
                name: pathinfo($path, PATHINFO_FILENAME),
                extensions: [basename($path)],
            );
        }

        if (! is_dir($path)) {
            throw new \RuntimeException(sprintf('Extension package path not found: %s', $path));
        }

        $composerPath = rtrim($path, '/').'/composer.json';
        if (is_file($composerPath)) {
            $decoded = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
            $extra = is_array($decoded['extra']['pi'] ?? null) ? $decoded['extra']['pi'] : [];
            $name = is_string($decoded['name'] ?? null) && $decoded['name'] !== ''
                ? $decoded['name']
                : basename($path);

            $extensions = $this->stringList($extra['extensions'] ?? []);
            $skills = $this->stringList($extra['skills'] ?? []);
            $prompts = $this->stringList($extra['prompts'] ?? []);
            $themes = $this->stringList($extra['themes'] ?? []);

            if ($extensions === []) {
                $extensions = $this->fallbackEntries($path);
            }

            return new ExtensionPackageManifest($name, $extensions, $skills, $prompts, $themes);
        }

        return new ExtensionPackageManifest(
            name: basename($path),
            extensions: $this->fallbackEntries($path),
        );
    }

    /**
     * @return list<string>
     */
    private function fallbackEntries(string $directory): array
    {
        $entries = [];
        foreach (['index.php', 'extension.php'] as $entry) {
            $path = rtrim($directory, '/').'/'.$entry;
            if (is_file($path)) {
                return [$entry];
            }
        }

        $scan = scandir($directory);
        if (! is_array($scan)) {
            return [];
        }

        foreach ($scan as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = rtrim($directory, '/').'/'.$entry;
            if (is_file($path) && str_ends_with(strtolower($entry), '.php')) {
                $entries[] = $entry;
            }
        }

        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
