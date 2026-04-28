<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\OAuth\OAuthCredentials;
use Pi\AI\OAuth\OAuthLoginCallbacks;
use Pi\AI\OAuth\OAuthProviderInterface;
use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\CodingAgentConfig;
use Pi\CodingAgent\CodingAgentRuntime;
use Pi\CodingAgent\CodingAgentRuntimeFactory;
use Pi\CodingAgent\Session\FilesystemSessionStore;
use Pi\Console\AuthCommand;
use Pi\Console\LoginCommand;
use Pi\Console\LogoutCommand;
use Pi\Console\ModelsCommand;
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
