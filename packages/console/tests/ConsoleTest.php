<?php

declare(strict_types=1);
use Pi\CodingAgent\Auth\AuthStorage;

require_once __DIR__.'/TestHelper.php';

describe('Console cli', function () {
    it('supports text mode through bin/pi', function () {
        $output = [];
        $exitCode = 0;
        exec('PI_CODING_AGENT_FAUX_RESPONSE="console text" php '.escapeshellarg(getcwd().'/bin/pi').' --mode text --provider faux --no-session "hello"', $output, $exitCode);

        expect($exitCode)->toBe(0);
        expect(implode("\n", $output))->toContain('console text');
    });

    it('streams json mode events through bin/pi', function () {
        $output = [];
        $exitCode = 0;
        exec('PI_CODING_AGENT_FAUX_RESPONSE="console json" php '.escapeshellarg(getcwd().'/bin/pi').' --mode json --provider faux --no-session "hello"', $output, $exitCode);

        expect($exitCode)->toBe(0);
        expect($output[0] ?? '')->toContain('"type":"session"');
        expect(implode("\n", $output))->toContain('"type":"agent_start"');
        expect(implode("\n", $output))->toContain('"type":"agent_end"');
    });

    it('routes extension notifications to stderr through the runtime surface', function () {
        $dir = codingAgentTempDir('console-output-guard');
        file_put_contents($dir.'/composer.json', json_encode([
            'name' => 'test/output-guard',
            'extra' => [
                'pi' => [
                    'extensions' => ['ext.php'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/ext.php', <<<'PHP'
<?php

declare(strict_types=1);

use Pi\CodingAgent\Extension\ExtensionAPI;
use Pi\CodingAgent\Extension\ExtensionContext;

return static function (ExtensionAPI $api): void {
    $api->on('input', static function (array $_event, ExtensionContext $context): void {
        $context->ui->notify('guard notice', 'info');
    });
};
PHP);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $code = sprintf(
            <<<'PHP'
require %s;

$command = new \Pi\Console\MainCommand;
$runtime = $command->createRuntimeFromCwd(%s);
$runtime->getExtensionRunner()?->emit('input', ['type' => 'input', 'input' => 'hello']);
PHP,
            var_export(getcwd().'/vendor/autoload.php', true),
            var_export($dir, true),
        );

        $process = proc_open('php -r '.escapeshellarg($code), $descriptorSpec, $pipes, getcwd());
        expect(is_resource($process))->toBeTrue();

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        codingAgentDeleteDir($dir);

        expect($exitCode)->toBe(0);
        expect($stdout)->toBe('');
        expect($stderr)->toContain('[info] guard notice');
    });

    it('handles rpc mode over stdin and stdout', function () {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, ['PI_CODING_AGENT_FAUX_RESPONSE' => 'rpc answer']);
        $process = proc_open('php '.escapeshellarg(getcwd().'/bin/pi').' --mode rpc --provider faux --no-session', $descriptorSpec, $pipes, getcwd(), $env);
        expect(is_resource($process))->toBeTrue();

        fwrite($pipes[0], json_encode(['id' => '1', 'type' => 'prompt', 'message' => 'hello'])."\n");
        fwrite($pipes[0], json_encode(['id' => '2', 'type' => 'state'])."\n");
        fwrite($pipes[0], json_encode(['id' => '3', 'type' => 'shutdown'])."\n");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        expect($stderr)->toBe('');
        expect($exitCode)->toBe(0);
        expect($stdout)->toContain('"type":"agent_start"');
        expect($stdout)->toContain('"id":"1"');
        expect($stdout)->toContain('"command":"prompt"');
        expect($stdout)->toContain('"success":true');
        expect($stdout)->toContain('rpc answer');
    });

    it('accepts @file arguments as input context', function () {
        $dir = codingAgentTempDir('console-file');
        file_put_contents($dir.'/note.txt', "hello from file\n");

        $output = [];
        $exitCode = 0;
        exec('PI_CODING_AGENT_FAUX_RESPONSE="file context" php '.escapeshellarg(getcwd().'/bin/pi').' --mode text --provider faux --no-session --cwd '.escapeshellarg($dir).' @note.txt', $output, $exitCode);

        codingAgentDeleteDir($dir);

        expect($exitCode)->toBe(0);
        expect(implode("\n", $output))->toContain('file context');
    });

    it('supports usable-only model listing through models command', function () {
        $dir = codingAgentTempDir('console-usable-models');
        putenv('PI_CODING_AGENT_DIR='.$dir.'/.agent');
        $auth = AuthStorage::create($dir.'/.agent/auth.json');
        $auth->set('github-copilot', [
            'type' => 'oauth',
            'access' => 'tid=1;proxy-ep=proxy.enterprise.githubcopilot.com;exp=999',
            'refresh' => 'refresh-token',
            'expires' => time() * 1000 + 3600_000,
        ]);

        $output = [];
        $exitCode = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' models list --usable --cwd '.escapeshellarg($dir), $output, $exitCode);

        codingAgentDeleteDir($dir);

        expect($exitCode)->toBe(0);
        expect(implode("\n", $output))->toContain('github-copilot');
        expect(implode("\n", $output))->not->toContain('openai/');
    });

    it('shows the application command list and help pages', function () {
        $listOutput = [];
        $listExit = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' list', $listOutput, $listExit);

        expect($listExit)->toBe(0);
        expect(implode("\n", $listOutput))->toContain('login');
        expect(implode("\n", $listOutput))->toContain('models');
        expect(implode("\n", $listOutput))->toContain('extensions');

        $helpOutput = [];
        $helpExit = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' help models', $helpOutput, $helpExit);

        expect($helpExit)->toBe(0);
        expect(implode("\n", $helpOutput))->toContain('Usage:');
        expect(implode("\n", $helpOutput))->toContain('models [options]');

        $extensionsHelpOutput = [];
        $extensionsHelpExit = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' help extensions', $extensionsHelpOutput, $extensionsHelpExit);

        expect($extensionsHelpExit)->toBe(0);
        expect(implode("\n", $extensionsHelpOutput))->toContain('extensions [options]');
        expect(implode("\n", $extensionsHelpOutput))->toContain('install');
    });

    it('recognizes management commands when --cwd appears before the command name', function () {
        $dir = codingAgentTempDir('console-command-routing');

        $listOutput = [];
        $listExit = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' --cwd '.escapeshellarg($dir).' list', $listOutput, $listExit);

        expect($listExit)->toBe(0);
        expect(implode("\n", $listOutput))->toContain('login');
        expect(implode("\n", $listOutput))->toContain('models');

        $modelsOutput = [];
        $modelsExit = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' --cwd '.escapeshellarg($dir).' models list', $modelsOutput, $modelsExit);

        codingAgentDeleteDir($dir);

        expect($modelsExit)->toBe(0);
        expect(implode("\n", $modelsOutput))->toContain('Provider');
        expect(implode("\n", $modelsOutput))->toContain('Model');
    });

    it('manages extension packages through bin/pi and loads installed extension commands on the next run', function () {
        $dir = codingAgentTempDir('console-extension-cli');
        putenv('PI_CODING_AGENT_DIR='.$dir.'/.agent');
        $package = createExtensionPackageFixture(
            $dir.'/fixtures/cli-package',
            'fixture/cli-package',
            ['index.php'],
        );

        $installOutput = [];
        $installExit = 0;
        exec(
            'php '.escapeshellarg(getcwd().'/bin/pi').' extensions install '.escapeshellarg($package).' --cwd '.escapeshellarg($dir),
            $installOutput,
            $installExit,
        );
        expect($installExit)->toBe(0);
        expect(implode("\n", $installOutput))->toContain('Installed fixture-cli-package');

        $listOutput = [];
        $listExit = 0;
        exec(
            'php '.escapeshellarg(getcwd().'/bin/pi').' extensions list --json --cwd '.escapeshellarg($dir),
            $listOutput,
            $listExit,
        );
        expect($listExit)->toBe(0);
        expect(implode("\n", $listOutput))->toContain('"id": "fixture-cli-package"');

        $extensionOutput = [];
        $extensionExit = 0;
        exec(
            'php '.escapeshellarg(getcwd().'/bin/pi').' managed-ext hello --cwd '.escapeshellarg($dir),
            $extensionOutput,
            $extensionExit,
        );
        expect($extensionExit)->toBe(0);
        expect(implode("\n", $extensionOutput))->toContain('managed hello');

        $disableOutput = [];
        $disableExit = 0;
        exec(
            'php '.escapeshellarg(getcwd().'/bin/pi').' extensions disable fixture-cli-package --cwd '.escapeshellarg($dir),
            $disableOutput,
            $disableExit,
        );
        expect($disableExit)->toBe(0);

        $disabledExtensionOutput = [];
        $disabledExtensionExit = 0;
        exec(
            'php '.escapeshellarg(getcwd().'/bin/pi').' help managed-ext --cwd '.escapeshellarg($dir).' 2>&1',
            $disabledExtensionOutput,
            $disabledExtensionExit,
        );
        expect($disabledExtensionExit)->not->toBe(0);
        expect(implode("\n", $disabledExtensionOutput))->toContain('Command "managed-ext" is not defined.');

        $missingInstallOutput = [];
        $missingInstallExit = 0;
        exec(
            'php '.escapeshellarg(getcwd().'/bin/pi').' extensions install '.escapeshellarg($dir.'/missing-package').' --cwd '.escapeshellarg($dir).' 2>&1',
            $missingInstallOutput,
            $missingInstallExit,
        );

        codingAgentDeleteDir($dir);

        expect($missingInstallExit)->not->toBe(0);
        expect(implode("\n", $missingInstallOutput))->toContain('Extension package path not found');
    });
});
