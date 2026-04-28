<?php

declare(strict_types=1);

use Pi\Console\MainCommand;
use Pi\Console\ParsedInput;
use Symfony\Component\Console\Input\ArgvInput;

describe('Main command input parsing', function () {
    it('does not treat an absent --resume option as resume latest', function () {
        $command = new MainCommand;
        $input = new ArgvInput(['bin/pi']);
        $input->bind($command->getDefinition());

        $parsed = invokeParseInput($command, $input);

        expect($parsed->resume)->toBeFalse();
        expect($parsed->continueLatest)->toBeFalse();
    });

    it('treats a bare --resume flag as continue latest', function () {
        $command = new MainCommand;
        $input = new ArgvInput(['bin/pi', '--resume']);
        $input->bind($command->getDefinition());

        $parsed = invokeParseInput($command, $input);

        expect($parsed->resume)->toBeTrue();
    });
});

function invokeParseInput(MainCommand $command, ArgvInput $input): ParsedInput
{
    $method = new ReflectionMethod($command, 'parseInput');

    /** @var ParsedInput */
    return $method->invoke($command, $input);
}
