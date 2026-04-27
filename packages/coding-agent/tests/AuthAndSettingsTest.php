<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\Agent\ThinkingLevel;
use Pi\AI\OAuth\OAuthHttp;
use Pi\CodingAgent\Auth\AuthStorage;
use Pi\CodingAgent\Settings\SettingsManager;

describe('Coding agent auth storage and settings manager', function () {
    it('resolves auth sources in the expected precedence order', function () {
        putenv('OPENAI_API_KEY');
        unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);

        $auth = AuthStorage::inMemory([
            'openai' => ['type' => 'api_key', 'key' => 'stored-key'],
        ]);

        expect(codingAgentBlock($auth->getApiKey('openai')))->toBe('stored-key');
        expect($auth->getStatus('openai'))->toBe([
            'configured' => true,
            'source' => 'stored',
            'label' => 'Stored API key',
        ]);

        putenv('OPENAI_API_KEY=env-key');
        $_ENV['OPENAI_API_KEY'] = 'env-key';
        $_SERVER['OPENAI_API_KEY'] = 'env-key';
        expect(codingAgentBlock($auth->getApiKey('openai')))->toBe('stored-key');

        $auth->setRuntimeApiKey('openai', 'runtime-key');
        expect(codingAgentBlock($auth->getApiKey('openai')))->toBe('runtime-key');
        expect($auth->getStatus('openai')['source'])->toBe('runtime');

        $auth->removeRuntimeApiKey('openai');
        $auth->set('openai', null);
        expect(codingAgentBlock($auth->getApiKey('openai')))->toBe('env-key');
        expect($auth->getStatus('openai')['source'])->toBe('environment');

        $auth->setFallbackResolver(static fn (string $provider): ?string => $provider === 'anthropic' ? 'fallback-key' : null);
        expect(codingAgentBlock($auth->getApiKey('anthropic')))->toBe('fallback-key');
        expect($auth->getStatus('anthropic')['source'])->toBe('fallback');

        putenv('OPENAI_API_KEY');
        unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
    });

    it('supports file-backed auth storage', function () {
        $dir = codingAgentTempDir();
        $auth = AuthStorage::create($dir.'/auth.json');

        $auth->set('openai', ['type' => 'api_key', 'key' => 'disk-key']);
        $reloaded = AuthStorage::create($dir.'/auth.json');

        expect(codingAgentBlock($reloaded->getApiKey('openai')))->toBe('disk-key');
        expect($reloaded->list())->toBe(['openai']);

        codingAgentDeleteDir($dir);
    });

    it('refreshes stored oauth credentials and persists the updated token set', function () {
        $dir = codingAgentTempDir();
        OAuthHttp::setClientForTesting(static fn () => [
            'status' => 200,
            'body' => json_encode([
                'access_token' => 'access-new',
                'refresh_token' => 'refresh-new',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR),
        ]);

        $auth = AuthStorage::create($dir.'/auth.json');
        $auth->set('anthropic', [
            'type' => 'oauth',
            'access' => 'access-old',
            'refresh' => 'refresh-old',
            'expires' => 0,
        ]);

        expect(codingAgentBlock($auth->getApiKey('anthropic')))->toBe('access-new');

        $reloaded = AuthStorage::create($dir.'/auth.json');
        expect($reloaded->get('anthropic')['refresh'] ?? null)->toBe('refresh-new');
        expect($reloaded->get('anthropic')['access'] ?? null)->toBe('access-new');

        OAuthHttp::setClientForTesting(null);
        codingAgentDeleteDir($dir);
    });

    it('deep merges global and project settings and reloads them', function () {
        $dir = codingAgentTempDir();
        $settings = SettingsManager::create($dir, $dir.'/.agent-home');

        $settings->setGlobalSettings([
            'defaultProvider' => 'openai',
            'defaultModel' => 'gpt-5.4-mini',
            'defaultThinkingLevel' => 'low',
            'compaction' => [
                'enabled' => true,
                'reserveTokens' => 2048,
            ],
        ]);
        $settings->setProjectSettings([
            'defaultThinkingLevel' => 'high',
            'sessionDir' => $dir.'/custom-sessions',
            'compaction' => [
                'keepRecentTokens' => 4096,
            ],
        ]);

        expect($settings->getDefaultProvider())->toBe('openai');
        expect($settings->getDefaultModel())->toBe('gpt-5.4-mini');
        expect($settings->getDefaultThinkingLevel())->toBe(ThinkingLevel::High);
        expect($settings->getSessionDir($dir))->toBe($dir.'/custom-sessions');
        expect($settings->getCompactionEnabled())->toBeTrue();
        expect($settings->getCompactionReserveTokens())->toBe(2048);
        expect($settings->getCompactionKeepRecentTokens())->toBe(4096);

        file_put_contents($dir.'/.pi/settings.json', json_encode(['defaultThinkingLevel' => 'minimal'], JSON_THROW_ON_ERROR));
        $settings->reload();

        expect($settings->getDefaultThinkingLevel())->toBe(ThinkingLevel::Minimal);

        codingAgentDeleteDir($dir);
    });
});
