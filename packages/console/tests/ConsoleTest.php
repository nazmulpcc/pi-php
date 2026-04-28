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

        $helpOutput = [];
        $helpExit = 0;
        exec('php '.escapeshellarg(getcwd().'/bin/pi').' help models', $helpOutput, $helpExit);

        expect($helpExit)->toBe(0);
        expect(implode("\n", $helpOutput))->toContain('Usage:');
        expect(implode("\n", $helpOutput))->toContain('models [options]');
    });
});
