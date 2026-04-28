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
});
