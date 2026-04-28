<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';
require_once __DIR__.'/../../coding-agent/tests/TestHelper.php';

use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use Pi\AI\OAuth\OAuthProviderInterface;
use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntime;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Extension\Package\ExtensionPackageManager;
use Pi\CodingAgent\Extension\Package\ExtensionPackageScope;
use Pi\CodingAgent\Extension\Package\ExtensionPackageSourceType;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\Console\AuthCommand;
use Pi\Console\DiagnosticsCommand;
use Pi\Console\ExtensionsCommand;
use Pi\Console\LoginCommand;
use Pi\Console\LogoutCommand;
use Pi\Console\ModelsCommand;
use Pi\Console\ReplSlashCommandCompleter;
use Pi\Console\ReplSlashCommandHandler;
use Pi\Console\ResourcesCommand;
use Pi\Console\SessionsCommand;
use Pi\Console\SettingsCommand;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function Pi\AI\fauxAssistantMessage;
use function Pi\AI\registerFauxProvider;
use function Pi\AI\registerOAuthProvider;
use function Pi\AI\resetOAuthProviders;
use function React\Promise\resolve;

describe('Console management commands', function () {
    beforeEach(function () {
        resetOAuthProviders();
    });

    afterEach(function () {
        resetOAuthProviders();
        putenv('PI_CODING_AGENT_DIR');
    });

    it('supports login, auth status, and logout commands', function () {
        $dir = codingAgentTempDir('console-auth');
        putenv('PI_CODING_AGENT_DIR='.$dir.'/.agent');

        registerOAuthProvider(new class implements OAuthProviderInterface
        {
            public function getId(): string
            {
                return 'test-oauth';
            }

            public function getName(): string
            {
                return 'Test OAuth';
            }

            public function usesCallbackServer(): bool
            {
                return false;
            }

            public function login(OAuthLoginCallbacks $callbacks): PromiseInterface
            {
                return resolve(new OAuthCredentials('refresh-token', 'access-token', time() * 1000 + 3600_000));
            }

            public function refreshToken(OAuthCredentials $credentials): PromiseInterface
            {
                return resolve($credentials);
            }

            public function getApiKey(OAuthCredentials $credentials): string
            {
                return $credentials->access;
            }

            public function modifyModels(array $models, OAuthCredentials $credentials): array
            {
                return $models;
            }
        });

        $login = new CommandTester(new LoginCommand);
        expect($login->execute(['provider' => 'test-oauth']))->toBe(0);

        $status = new CommandTester(new AuthCommand);
        expect($status->execute(['action' => 'status']))->toBe(0);
        expect($status->getDisplay())->toContain('test-oauth');
        expect($status->getDisplay())->toContain('Stored OAuth token');

        $logout = new CommandTester(new LogoutCommand);
        expect($logout->execute(['provider' => 'test-oauth']))->toBe(0);

        $status = new CommandTester(new AuthCommand);
        $status->execute(['action' => 'status']);
        expect($status->getDisplay())->toContain('test-oauth');
        expect($status->getDisplay())->toContain('no');

        codingAgentDeleteDir($dir);
    });

    it('lists, shows, exports, and forks persisted sessions', function () {
        $dir = codingAgentTempDir('console-sessions');
        $runtime = createPersistedFauxRuntime($dir, 'session answer');
        codingAgentBlock($runtime->prompt('hello'));
        $sessionPath = $runtime->getState()->sessionPath;
        expect($sessionPath)->not->toBeNull();

        $command = new CommandTester(new SessionsCommand);
        expect($command->execute([
            'action' => 'list',
            '--cwd' => $dir,
            '--session-dir' => $dir.'/.pi/sessions',
        ]))->toBe(0);
        expect($command->getDisplay())->toContain($runtime->getState()->sessionId);

        $command = new CommandTester(new SessionsCommand);
        $command->execute([
            'action' => 'show',
            'target' => $runtime->getState()->sessionId,
            '--cwd' => $dir,
            '--session-dir' => $dir.'/.pi/sessions',
        ]);
        expect($command->getDisplay())->toContain('messageCount');
        expect($command->getDisplay())->toContain($runtime->getState()->sessionId);

        $exportPath = $dir.'/exported.html';
        $command = new CommandTester(new SessionsCommand);
        $command->execute([
            'action' => 'export',
            'target' => $sessionPath,
            'output' => $exportPath,
            '--cwd' => $dir,
            '--session-dir' => $dir.'/.pi/sessions',
        ]);
        expect(is_file($exportPath))->toBeTrue();
        expect((string) file_get_contents($exportPath))->toContain('session answer');

        $command = new CommandTester(new SessionsCommand);
        $command->execute([
            'action' => 'fork',
            'target' => $sessionPath,
            '--cwd' => $dir,
            '--session-dir' => $dir.'/.pi/sessions',
        ]);
        expect($command->getDisplay())->toContain('Forked');

        codingAgentDeleteDir($dir);
    });

    it('shows and updates settings, reports resources, and lists models', function () {
        $dir = codingAgentTempDir('console-settings');
        putenv('PI_CODING_AGENT_DIR='.$dir.'/.agent');
        file_put_contents($dir.'/AGENTS.md', "Top level context\n");
        mkdir($dir.'/skills', 0777, true);
        file_put_contents($dir.'/skills/debug.md', "# Debug\n");
        mkdir($dir.'/prompt-templates', 0777, true);
        file_put_contents($dir.'/prompt-templates/review.md', "# Review\n");
        $auth = AuthStorage::create($dir.'/.agent/auth.json');
        $auth->set('github-copilot', [
            'type' => 'oauth',
            'access' => 'tid=1;proxy-ep=proxy.enterprise.githubcopilot.com;exp=999',
            'refresh' => 'refresh-token',
            'expires' => time() * 1000 + 3600_000,
        ]);
        $package = createExtensionPackageFixture(
            $dir.'/fixtures/resource-package',
            'fixture/resource-package',
            ['index.php'],
            ['managed-skills'],
            ['managed-prompts'],
            ['managed-themes'],
        );
        (new ExtensionPackageManager($dir, $dir.'/.agent'))
            ->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::PROJECT);

        $settings = new CommandTester(new SettingsCommand);
        expect($settings->execute([
            'action' => 'set',
            'key' => 'defaultThinkingLevel',
            'value' => '"high"',
            '--cwd' => $dir,
        ]))->toBe(0);
        expect($settings->getDisplay())->toContain('Updated project setting');

        $settings = new CommandTester(new SettingsCommand);
        $settings->execute([
            'action' => 'show',
            '--cwd' => $dir,
        ]);
        expect($settings->getDisplay())->toContain('"defaultThinkingLevel": "high"');

        $resources = new CommandTester(new ResourcesCommand);
        $resources->execute([
            'action' => 'show',
            '--cwd' => $dir,
        ]);
        expect($resources->getDisplay())->toContain('AGENTS.md');
        expect($resources->getDisplay())->toContain('debug');
        expect($resources->getDisplay())->toContain('review');
        expect($resources->getDisplay())->toContain('managed-skills');
        expect($resources->getDisplay())->toContain('managed-prompts');

        $models = new CommandTester(new ModelsCommand);
        $models->execute([
            'action' => 'list',
            'search' => 'gpt-5.2-codex',
            '--usable' => true,
            '--cwd' => $dir,
        ]);
        expect($models->getDisplay())->toContain('gpt-5.2-codex');
        expect($models->getDisplay())->toContain('stored');

        codingAgentDeleteDir($dir);
    });

    it('surfaces diagnostics from auth, settings, resources, and models', function () {
        $dir = codingAgentTempDir('console-diagnostics');
        putenv('PI_CODING_AGENT_DIR='.$dir.'/.agent');
        mkdir($dir.'/.agent', 0777, true);
        file_put_contents($dir.'/.agent/auth.json', '{not valid json');
        file_put_contents($dir.'/.agent/settings.json', '{not valid json');
        mkdir($dir.'/.pi', 0777, true);
        file_put_contents($dir.'/.pi/settings.json', json_encode([
            'defaultProvider' => 'missing-provider',
            'defaultModel' => 'missing-model',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/.pi/packages.json', '{not valid json');
        file_put_contents($dir.'/AGENTS.md', "Unreadable context\n");
        chmod($dir.'/AGENTS.md', 0000);

        $diagnostics = new CommandTester(new DiagnosticsCommand);
        $diagnostics->execute([
            '--cwd' => $dir,
        ]);
        $display = $diagnostics->getDisplay();
        $sessionFiles = glob($dir.'/.pi/sessions/*.jsonl') ?: [];

        chmod($dir.'/AGENTS.md', 0644);
        codingAgentDeleteDir($dir);

        expect($display)->toContain('Diagnostics');
        expect($display)->toContain('auth');
        expect($display)->toContain('settings');
        expect($display)->toContain('models');
        expect($display)->toContain('extension-package');
        expect($sessionFiles)->toBe([]);
    });

    it('handles repl slash commands against the runtime surface', function () {
        $dir = codingAgentTempDir('console-repl');
        $runtime = createPersistedFauxRuntime($dir, 'slash answer');
        codingAgentBlock($runtime->prompt('hello'));
        $handler = new ReplSlashCommandHandler;

        $session = $handler->handle('/session', $runtime);
        expect($session['handled'])->toBeTrue();
        expect($session['output'] ?? '')->toContain('session:');

        $thinking = $handler->handle('/thinking high', $runtime);
        expect($thinking['output'] ?? '')->toContain('high');

        $export = $handler->handle('/export '.$dir.'/slash.html', $runtime);
        expect($export['output'] ?? '')->toContain('exported:');
        expect(is_file($dir.'/slash.html'))->toBeTrue();

        $help = $handler->handle('/help', $runtime);
        expect($help['output'] ?? '')->toContain('/model');

        codingAgentDeleteDir($dir);
    });

    it('builds and filters repl slash command completions', function () {
        $handler = new ReplSlashCommandHandler;
        $completer = new ReplSlashCommandCompleter;

        expect($handler->getBuiltInCommands())->toContain('/model');
        expect($handler->getBuiltInCommands())->toContain('/continue');
        expect($completer->complete('/mo', $handler->getBuiltInCommands()))->toBe(['/model']);
        expect($completer->complete('/s', $handler->getBuiltInCommands()))->toBe(['/session', '/sessions']);
        expect($completer->complete('hello', $handler->getBuiltInCommands()))->toBe([]);
        expect($completer->complete('/unknown', $handler->getBuiltInCommands()))->toBe([]);
        expect($completer->complete('/pl', [...$handler->getBuiltInCommands(), '/plan']))->toBe(['/plan']);
    });

    it('lists installs updates toggles and removes managed extension packages', function () {
        $dir = codingAgentTempDir('console-extensions');
        putenv('PI_CODING_AGENT_DIR='.$dir.'/.agent');

        $package = createExtensionPackageFixture(
            $dir.'/fixtures/local-package',
            'fixture/local-package',
            ['index.php'],
            ['skills'],
            ['prompts'],
            ['themes'],
        );

        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'install',
            'target' => $package,
            '--cwd' => $dir,
        ]))->toBe(0);
        expect($command->getDisplay())->toContain('Installed fixture-local-package');

        $command = new CommandTester(new ExtensionsCommand);
        $command->execute([
            'action' => 'list',
            '--cwd' => $dir,
        ]);
        expect($command->getDisplay())->toContain('fixture-local-package');
        expect($command->getDisplay())->toContain('project');

        $command = new CommandTester(new ExtensionsCommand);
        $command->execute([
            'action' => 'list',
            '--cwd' => $dir,
            '--json' => true,
        ]);
        $payload = json_decode($command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        expect($payload['packages'][0]['id'] ?? null)->toBe('fixture-local-package');
        expect($payload['diagnostics'] ?? null)->toBeArray();

        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'disable',
            'target' => 'fixture-local-package',
            '--cwd' => $dir,
        ]))->toBe(0);
        expect($command->getDisplay())->toContain('Disabled fixture-local-package');

        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'enable',
            'target' => 'fixture-local-package',
            '--cwd' => $dir,
        ]))->toBe(0);
        expect($command->getDisplay())->toContain('Enabled fixture-local-package');

        file_put_contents($package.'/index.php', "<?php\n\nreturn function (\$api): void {\n    \$api->registerCommand('updated-ext', 'Updated', fn (string \$args): string => 'updated '.\$args);\n};\n");
        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'update',
            'target' => 'fixture-local-package',
            '--cwd' => $dir,
        ]))->toBe(0);
        expect($command->getDisplay())->toContain('Updated fixture-local-package');

        $installed = $dir.'/.pi/packages/fixture-local-package/index.php';
        expect(is_file($installed))->toBeTrue();
        expect((string) file_get_contents($installed))->toContain('updated-ext');

        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'remove',
            'target' => 'fixture-local-package',
            '--cwd' => $dir,
        ]))->toBe(0);
        expect($command->getDisplay())->toContain('Removed fixture-local-package');

        $command = new CommandTester(new ExtensionsCommand);
        $command->execute([
            'action' => 'list',
            '--cwd' => $dir,
            '--json' => true,
        ]);
        $payload = json_decode($command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        expect($payload['packages'])->toBe([]);

        codingAgentDeleteDir($dir);
    });

    it('supports global scope and surfaces invalid package inventory diagnostics', function () {
        $dir = codingAgentTempDir('console-extensions-global');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);
        mkdir($dir.'/.pi', 0777, true);
        mkdir($agentDir, 0777, true);
        file_put_contents($dir.'/.pi/packages.json', '{invalid');

        $package = createExtensionPackageFixture(
            $dir.'/fixtures/global-package',
            'fixture/global-package',
            ['index.php'],
        );

        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'install',
            'target' => $package,
            '--cwd' => $dir,
            '--global' => true,
        ]))->toBe(0);

        $command = new CommandTester(new ExtensionsCommand);
        $command->execute([
            'action' => 'list',
            '--cwd' => $dir,
            '--global' => true,
        ]);
        expect($command->getDisplay())->toContain('fixture-global-package');
        expect($command->getDisplay())->toContain('global');
        expect($command->getDisplay())->toContain('Failed to load project package inventory');

        codingAgentDeleteDir($dir);
    });

    it('installs local php entry files and git-backed packages through extensions command', function () {
        $dir = codingAgentTempDir('console-extensions-sources');
        putenv('PI_CODING_AGENT_DIR='.$dir.'/.agent');

        $filePackage = $dir.'/single-extension.php';
        file_put_contents($filePackage, <<<'PHP'
<?php

return function ($api): void {
    $api->registerCommand('single-ext', 'Single', fn (string $args): string => 'single '.$args);
};
PHP);

        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'install',
            'target' => $filePackage,
            '--cwd' => $dir,
        ]))->toBe(0);
        expect($command->getDisplay())->toContain('Installed single-extension');

        $gitPackage = createExtensionPackageFixture(
            $dir.'/fixtures/git-package',
            'fixture/git-package',
            ['index.php'],
        );
        exec(sprintf('git init %s >/dev/null 2>&1', escapeshellarg($gitPackage)));
        exec(sprintf('git -C %s config user.email %s', escapeshellarg($gitPackage), escapeshellarg('test@example.com')));
        exec(sprintf('git -C %s config user.name %s', escapeshellarg($gitPackage), escapeshellarg('Test User')));
        exec(sprintf('git -C %s add .', escapeshellarg($gitPackage)));
        exec(sprintf('git -C %s commit -m %s >/dev/null 2>&1', escapeshellarg($gitPackage), escapeshellarg('init')));

        $command = new CommandTester(new ExtensionsCommand);
        expect($command->execute([
            'action' => 'install',
            'target' => $gitPackage,
            '--cwd' => $dir,
            '--type' => 'git',
        ]))->toBe(0);
        expect($command->getDisplay())->toContain('Installed fixture-git-package');

        codingAgentDeleteDir($dir);
    });
});

function createPersistedFauxRuntime(string $cwd, string $response): CodingAgentRuntime
{
    $provider = registerFauxProvider([
        'provider' => 'faux',
        'api' => 'faux',
    ]);
    $provider->setResponses([fauxAssistantMessage($response)]);
    $model = $provider->getModel();

    return (new CodingAgentRuntimeFactory)->create(new CodingAgentConfig(
        cwd: $cwd,
        model: $model,
        provider: 'faux',
        modelId: $model?->id,
        sessionStore: new FilesystemSessionStore($cwd.'/.pi/sessions'),
    ));
}
