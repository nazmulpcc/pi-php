<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

describe('Coding agent cli compatibility wrapper', function () {
    it('keeps bin/pi working through the new console application', function () {
        $output = [];
        $exitCode = 0;
        exec('PI_CODING_AGENT_FAUX_RESPONSE="cli compatibility" php '.escapeshellarg(getcwd().'/bin/pi').' --mode text --provider faux --no-session "hello"', $output, $exitCode);

        expect($exitCode)->toBe(0);
        expect(implode("\n", $output))->toContain('cli compatibility');
    });
});
