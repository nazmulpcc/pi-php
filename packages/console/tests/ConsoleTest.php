<?php

declare(strict_types=1);

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
});
