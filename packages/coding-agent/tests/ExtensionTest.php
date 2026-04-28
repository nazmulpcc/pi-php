<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\Content\TextContent;
use Pi\Agent\ThinkingLevel;
use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Extension\ExtensionLoader;
use Pi\CodingAgent\Extension\HeadlessExtensionUI;
use Pi\CodingAgent\Settings\SettingsManager;

use function Pi\AI\fauxAssistantMessage;
use function Pi\AI\registerFauxProvider;

describe('coding agent extensions', function (): void {
    it('discovers project extensions, contributes tools, transforms input, and appends custom entries', function (): void {
        $dir = codingAgentTempDir('coding-agent-extension');
        mkdir($dir.'/.pi/extensions', 0777, true);

        file_put_contents($dir.'/.pi/extensions/demo.php', <<<'PHP'
<?php

use Pi\Agent\Content\TextContent;
use Pi\Agent\Tool\AgentToolResult;
use Pi\Agent\ToolExecutionMode;

return function ($api): void {
    $api->registerTool(
        'echo-ext',
        'Echo Ext',
        'Echo extension tool',
        ['type' => 'object'],
        function (string $toolCallId, array $params): AgentToolResult {
            return new AgentToolResult([new TextContent('echo-ext')]);
        },
        ToolExecutionMode::Sequential,
    );

    $api->registerCommand('ext-hello', 'Say hello', function (string $args) use ($api): string {
        return (($api->getFlag('loud') === true) ? 'LOUD ' : '').trim('hello '.$args);
    });

    $api->registerCommand('ext-entry', 'Append extension entry', function (string $args) use ($api): string {
        $api->appendEntry('assistant_seen', ['value' => trim($args)]);

        return 'entry appended';
    });

    $api->registerFlag('loud', 'Enable loud extension output', 'boolean', false);

    $api->on('input', function (array $event): array {
        return ['input' => $event['input'].' [ext]'];
    });
};
PHP);

        $settings = SettingsManager::create($dir);
        $loader = new ExtensionLoader;
        $loadResult = $loader->discover($dir, $settings);

        expect($loadResult->extensions)->toHaveCount(1);

        $provider = registerFauxProvider(['provider' => 'faux-ext', 'api' => 'faux-ext']);
        $provider->setResponses([fauxAssistantMessage('extension ok')]);

        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            provider: 'faux-ext',
            modelId: 'faux-ext',
            cwd: $dir,
            thinkingLevel: ThinkingLevel::Medium,
            enableContextFiles: false,
            extensions: $loadResult->extensions,
            extensionFlagValues: ['loud' => true],
        ));

        codingAgentBlock($runtime->prompt('hello'));

        $messages = $runtime->getState()->messages;
        expect($messages[0]->content[0])->toBeInstanceOf(TextContent::class);
        expect($messages[0]->content[0]->text)->toContain('[ext]');
        expect($runtime->getState()->toolNames)->toContain('echo-ext');
        expect($runtime->getExtensionRunner()?->executeCommand('ext-hello', 'world'))->toBe('LOUD hello world');
        expect($runtime->getExtensionRunner()?->executeCommand('ext-entry', 'custom'))->toBe('entry appended');
        expect(array_values(array_filter(
            $runtime->session->sessionManager->getEntries(),
            static fn (array $entry): bool => ($entry['type'] ?? null) === 'custom' && ($entry['customType'] ?? null) === 'assistant_seen',
        )))->toHaveCount(1);

        codingAgentDeleteDir($dir);
    });

    it('discovers manifest-declared extension entries in composer extra.pi.extensions', function (): void {
        $dir = codingAgentTempDir('coding-agent-extension-manifest');
        mkdir($dir.'/.pi/extensions/package/src', 0777, true);
        file_put_contents($dir.'/.pi/extensions/package/composer.json', json_encode([
            'extra' => [
                'pi' => [
                    'extensions' => ['src/manifest.php'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/.pi/extensions/package/src/manifest.php', <<<'PHP'
<?php

return function ($api): void {
    $api->registerCommand('manifest-ext', 'Manifest extension', fn (string $args): string => 'manifest '.$args);
};
PHP);

        $settings = SettingsManager::create($dir);
        $loadResult = (new ExtensionLoader)->discover($dir, $settings);

        expect($loadResult->extensions)->toHaveCount(1);
        expect($loadResult->extensions[0]->resolvedPath)->toEndWith('/src/manifest.php');

        codingAgentDeleteDir($dir);
    });

    it('loads the plan extension from composer metadata and writes a session plan markdown file', function (): void {
        $dir = codingAgentTempDir('coding-agent-plan-extension');
        file_put_contents($dir.'/composer.json', json_encode([
            'extra' => [
                'pi' => [
                    'extensions' => [getcwd().'/packages/extension-plan'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $settings = SettingsManager::create($dir);
        $loadResult = (new ExtensionLoader)->discover($dir, $settings);

        expect(array_map(static fn ($extension): string => $extension->resolvedPath, $loadResult->extensions))
            ->toContain(getcwd().'/packages/extension-plan/index.php');

        $provider = registerFauxProvider(['provider' => 'faux-plan', 'api' => 'faux-plan']);
        $provider->setResponses([
            fauxAssistantMessage("# Plan\n\n- First plan"),
            fauxAssistantMessage("# Plan\n\n- Second plan"),
        ]);

        $notifications = [];
        $runtime = (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
            model: $provider->getModel(),
            provider: 'faux-plan',
            modelId: $provider->getModel()?->id,
            cwd: $dir,
            thinkingLevel: ThinkingLevel::Medium,
            enableContextFiles: false,
            extensions: $loadResult->extensions,
            extensionUi: new HeadlessExtensionUI(
                onNotify: static function (string $message, string $type) use (&$notifications): void {
                    $notifications[] = ['message' => $message, 'type' => $type];
                },
            ),
        ));

        $commandResult = $runtime->getExtensionRunner()?->executeCommand('plan', '');
        expect($commandResult)->toContain('Plan mode enabled');
        expect($runtime->getState()->toolNames)->toBe(['read', 'find', 'grep', 'ls']);

        codingAgentBlock($runtime->prompt('Map the work'));
        $messages = $runtime->getState()->messages;
        expect($messages[0]->content[0])->toBeInstanceOf(TextContent::class);
        expect($messages[0]->content[0]->text)->toContain('Plan mode is active.');

        $planFiles = glob($dir.'/.pi/plans/*.md');
        expect($planFiles)->not->toBeFalse();
        expect($planFiles)->toHaveCount(1);
        expect((string) file_get_contents($planFiles[0]))->toContain('# Plan');
        expect($notifications[0]['type'] ?? null)->toBe('info');

        codingAgentBlock($runtime->prompt('Refine the plan'));
        expect((string) file_get_contents($planFiles[0]))->toContain('Second plan');
        expect($notifications[1]['type'] ?? null)->toBe('warning');
        expect($notifications[1]['message'] ?? '')->toContain('Overwrote existing plan file');

        codingAgentDeleteDir($dir);
    });
});
