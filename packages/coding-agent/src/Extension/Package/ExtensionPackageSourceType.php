<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension\Package;

final class ExtensionPackageSourceType
{
    public const LOCAL = 'local';

    public const GIT = 'git';

    public const COMPOSER = 'composer';

    public static function assertValid(string $value): string
    {
        if (! in_array($value, [self::LOCAL, self::GIT, self::COMPOSER], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported extension package source type: %s', $value));
        }

        return $value;
    }
}
