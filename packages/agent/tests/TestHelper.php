<?php

declare(strict_types=1);

use React\Promise\PromiseInterface;

if (! function_exists('block')) {
    function block(PromiseInterface $promise): mixed
    {
        $value = null;
        $error = null;
        $settled = false;

        $promise->then(
            function ($v) use (&$value, &$settled) {
                $value = $v;
                $settled = true;
            },
            function ($e) use (&$error, &$settled) {
                $error = $e;
                $settled = true;
            }
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
