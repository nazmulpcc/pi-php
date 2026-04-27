<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Settings;

interface SettingsStorage
{
    public function read(string $scope): ?string;

    public function write(string $scope, string $content): void;
}
