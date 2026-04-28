<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

describe('console extensions', function (): void {
    it('loads extension commands and flags through bin/pi', function (): void {
        $dir = codingAgentTempDir('console-extension');
        mkdir($dir.'/.pi/extensions', 0777, true);
        file_put_contents($dir.'/.pi/extensions/demo.php', <<<'PHP'
<?php

return function ($api): void {
    $api->registerFlag('loud', 'Enable loud output', 'boolean', false);
    $api->registerCommand('ext-hello', 'Extension hello', function (string $args) use ($api): string {
        return (($api->getFlag('loud') === true) ? 'LOUD ' : '').trim('hello '.$args);
    });
};
PHP);

        $output = [];
        $exitCode = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' ext-hello world --cwd '.escapeshellarg($dir).' --loud', $output, $exitCode);

        codingAgentDeleteDir($dir);

        expect($exitCode)->toBe(0);
        expect(implode("\n", $output))->toContain('LOUD hello world');
    });

    it('supports the plan extension across separate CLI runs', function (): void {
        $dir = codingAgentTempDir('console-plan-extension');
        file_put_contents($dir.'/composer.json', json_encode([
            'extra' => [
                'pi' => [
                    'extensions' => [getcwd().'/packages/extension-plan'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $output = [];
        $exitCode = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' plan --cwd '.escapeshellarg($dir), $output, $exitCode);

        expect($exitCode)->toBe(0);
        expect(implode("\n", $output))->toContain('Plan mode enabled');

        $output = [];
        $exitCode = 0;
        exec(
            'PI_CODING_AGENT_FAUX_RESPONSE='.escapeshellarg("# Plan\n\n- Real life plan").' php '
            .escapeshellarg(getcwd().'/bin/pi')
            .' --mode text --provider faux --continue --cwd '.escapeshellarg($dir)
            .' "Plan the implementation"',
            $output,
            $exitCode,
        );

        $planFiles = glob($dir.'/.pi/plans/*.md');
        $planContents = is_array($planFiles) && isset($planFiles[0]) ? (string) file_get_contents($planFiles[0]) : '';

        codingAgentDeleteDir($dir);

        expect($exitCode)->toBe(0);
        expect($planFiles)->not->toBeFalse();
        expect($planFiles)->toHaveCount(1);
        expect($planContents)->toContain('# Plan');
    });
});
