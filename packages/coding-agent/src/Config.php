<?php

declare(strict_types=1);

namespace Pi\CodingAgent;

final class Config
{
    public const CONFIG_DIR_NAME = '.pi';

    public static function getAgentDir(): string
    {
        $override = getenv('PI_CODING_AGENT_DIR');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            $home = sys_get_temp_dir();
        }

        return rtrim($home, '/').'/'.self::CONFIG_DIR_NAME.'/agent';
    }
}
