<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\ThinkingLevel;
use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\Console\RuntimeFailureLogger;

use function Pi\AI\fauxAssistantMessage;
use function Pi\AI\registerFauxProvider;

describe('console runtime failure logger', function (): void {
    it('writes a session-scoped log file with state and recent events', function (): void {
        $dir = codingAgentTempDir('console-runtime-log');
        $provider = registerFauxProvider(['provider' => 'faux-log', 'api' => 'faux-log']);
        $provider->setResponses([fauxAssistantMessage('logged response')]);

        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            model: $provider->getModel(),
            provider: 'faux-log',
            modelId: $provider->getModel()?->id,
            cwd: $dir,
            thinkingLevel: ThinkingLevel::Medium,
            enableContextFiles: false,
        ));

        $events = [];
        $runtime->subscribe(function ($event) use (&$events): void {
            $events[] = $event;
        });

        codingAgentBlock($runtime->prompt('hello'));

        $path = (new RuntimeFailureLogger)->log($runtime, new RuntimeException('synthetic failure'), $events);
        $contents = (string) file_get_contents($path);

        expect(is_file($path))->toBeTrue();
        expect($contents)->toContain('synthetic failure');
        expect($contents)->toContain('"recentEvents"');
        expect($contents)->toContain('"recentSessionEntries"');
        expect($contents)->toContain($runtime->getState()->sessionId);

        codingAgentDeleteDir($dir);
    });
});
