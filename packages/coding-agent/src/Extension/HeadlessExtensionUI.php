<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Extension;

final class HeadlessExtensionUI implements ExtensionUI
{
    /**
     * @param  null|callable(string, string): void  $onNotify
     * @param  null|callable(string, string): bool  $onConfirm
     * @param  null|callable(string, ?string): ?string  $onInput
     */
    public function __construct(
        private readonly mixed $onNotify = null,
        private readonly mixed $onConfirm = null,
        private readonly mixed $onInput = null,
    ) {}

    public function notify(string $message, string $type = 'info'): void
    {
        if ($this->onNotify !== null) {
            ($this->onNotify)($message, $type);
        }
    }

    public function confirm(string $title, string $message): bool
    {
        if ($this->onConfirm !== null) {
            return (bool) ($this->onConfirm)($title, $message);
        }

        return false;
    }

    public function input(string $title, ?string $placeholder = null): ?string
    {
        if ($this->onInput !== null) {
            $result = ($this->onInput)($title, $placeholder);

            return is_string($result) && $result !== '' ? $result : null;
        }

        return null;
    }
}
