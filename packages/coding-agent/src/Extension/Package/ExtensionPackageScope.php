<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final class ExtensionPackageScope
{
    public const PROJECT = 'project';

    public const GLOBAL = 'global';

    public static function assertValid(string $value): string
    {
        if (! in_array($value, [self::PROJECT, self::GLOBAL], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported extension package scope: %s', $value));
        }

        return $value;
    }
}
