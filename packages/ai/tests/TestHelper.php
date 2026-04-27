<?php

declare(strict_types=1);

use React\Promise\PromiseInterface;

use function Pi\AI\packageRoot;

function aiPackageRoot(string $path = ''): string
{
    return packageRoot($path);
}

if (! function_exists('block')) {
    function block(PromiseInterface $promise): mixed
    {
        $value = null;
        $error = null;
        $settled = false;

        $promise->then(
            function ($resolved) use (&$value, &$settled): void {
                $value = $resolved;
                $settled = true;
            },
            function ($rejected) use (&$error, &$settled): void {
                $error = $rejected;
                $settled = true;
            },
        );

        if (! $settled) {
            throw new RuntimeException('Promise did not settle synchronously');
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }
}
