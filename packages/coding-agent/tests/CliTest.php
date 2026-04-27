<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\ThinkingLevel;
use Pi\CodingAgent\Cli\Application;

describe('Coding agent cli', function () {
    it('parses lightweight cli arguments', function () {
        $args = (new Application)->parseArgs([
            '--mode', 'json',
            '--provider', 'openai',
            '--model', 'gpt-5.4-mini',
            '--thinking', 'low',
            '--tools', 'read,bash',
            'hello',
        ]);

        expect($args->mode)->toBe('json');
        expect($args->provider)->toBe('openai');
        expect($args->modelId)->toBe('gpt-5.4-mini');
        expect($args->thinkingLevel)->toBe(ThinkingLevel::Low);
        expect($args->tools)->toBe(['read', 'bash']);
        expect($args->messages)->toBe(['hello']);
    });

    it('supports text mode through the bin script', function () {
        $output = [];
        $exitCode = 0;
        exec('PI_CODING_AGENT_FAUX_RESPONSE="cli text" php '.escapeshellarg(getcwd().'/bin/pi').' --mode text --provider faux --no-session "hello"', $output, $exitCode);

        expect($exitCode)->toBe(0);
        expect(implode("\n", $output))->toContain('cli text');
    });

    it('streams json mode events through the bin script', function () {
        $output = [];
        $exitCode = 0;
        exec('PI_CODING_AGENT_FAUX_RESPONSE="cli json" php '.escapeshellarg(getcwd().'/bin/pi').' --mode json --provider faux --no-session "hello"', $output, $exitCode);

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
});
