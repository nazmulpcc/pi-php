<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

interface ExtensionUI
{
    public function notify(string $message, string $type = 'info'): void;

    public function confirm(string $title, string $message): bool;

    public function input(string $title, ?string $placeholder = null): ?string;
}
