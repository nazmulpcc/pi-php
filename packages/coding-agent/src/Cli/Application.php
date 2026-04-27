<?php

declare(strict_types=1);

namespace Pi\CodingAgent\Cli;

use Pi\CodingAgent\Support\PromiseBlocker;
use Pi\Console\Application as ConsoleApplication;
use React\Promise\PromiseInterface;

final class Application
{
    public function run(array $argv): int
    {
        return (new ConsoleApplication)->run($argv);
    }
}

function block(PromiseInterface $promise): mixed
{
    return PromiseBlocker::block($promise);
}
