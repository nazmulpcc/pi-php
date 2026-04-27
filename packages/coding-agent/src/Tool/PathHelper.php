<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Tool;

final class PathHelper
{
    public static function resolve(string $cwd, string $path): string
    {
        if ($path === '') {
            throw new \RuntimeException('Path must not be empty');
        }

        $candidate = self::isAbsolute($path) ? $path : $cwd.DIRECTORY_SEPARATOR.$path;
        $normalized = self::normalize($candidate);
        $root = rtrim(self::normalize($cwd), DIRECTORY_SEPARATOR);

        if ($normalized !== $root && ! str_starts_with($normalized, $root.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(sprintf('Path escapes working directory: %s', $path));
        }

        return $normalized;
    }

    public static function relative(string $cwd, string $path): string
    {
        $root = rtrim(self::normalize($cwd), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = self::normalize($path);

        return str_starts_with($normalized, $root)
            ? substr($normalized, strlen($root))
            : $normalized;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = substr($path, 1);
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        $joined = implode(DIRECTORY_SEPARATOR, $parts);
        if ($prefix === '/') {
            return DIRECTORY_SEPARATOR.$joined;
        }
        if ($prefix !== '') {
            return $prefix.($joined !== '' ? DIRECTORY_SEPARATOR.$joined : '');
        }

        return $joined;
    }
}
