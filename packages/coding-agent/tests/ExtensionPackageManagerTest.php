<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\CodingAgent\Extension\ExtensionLoader;
use Pi\CodingAgent\Extension\Package\ExtensionPackageManager;
use Pi\CodingAgent\Extension\Package\ExtensionPackageScope;
use Pi\CodingAgent\Extension\Package\ExtensionPackageSourceType;
use Pi\CodingAgent\Resource\FilesystemResourceLoader;
use Pi\CodingAgent\Settings\SettingsManager;

describe('extension package manager', function (): void {
    it('loads and saves project and global package inventories', function (): void {
        $dir = codingAgentTempDir('extension-package-inventory');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);

        $package = createExtensionPackageFixture($dir.'/fixtures/local-package', 'fixture/local-package', ['index.php'], ['skills'], ['prompts'], ['themes']);
        $manager = new ExtensionPackageManager($dir, $agentDir);

        $project = $manager->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::PROJECT);
        $global = $manager->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::GLOBAL);

        $projectInventory = json_decode((string) file_get_contents($dir.'/.pi/packages.json'), true);
        $globalInventory = json_decode((string) file_get_contents($agentDir.'/packages.json'), true);

        expect($projectInventory['packages'][0]['id'] ?? null)->toBe($project->id);
        expect($globalInventory['packages'][0]['id'] ?? null)->toBe($global->id);

        putenv('PI_CODING_AGENT_DIR');
        codingAgentDeleteDir($dir);
    });

    it('reports invalid inventory diagnostics', function (): void {
        $dir = codingAgentTempDir('extension-package-invalid');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);
        mkdir($dir.'/.pi', 0777, true);
        mkdir($agentDir, 0777, true);
        file_put_contents($dir.'/.pi/packages.json', '{invalid');
        file_put_contents($agentDir.'/packages.json', json_encode(['packages' => [['id' => 'missing-fields']]], JSON_THROW_ON_ERROR));

        $manager = new ExtensionPackageManager($dir, $agentDir);
        $packages = $manager->listInstalledPackages();

        expect($packages)->toBe([]);
        expect($manager->getDiagnostics())->not->toBe([]);

        putenv('PI_CODING_AGENT_DIR');
        codingAgentDeleteDir($dir);
    });

    it('installs local packages into project and global scopes and resolves managed resources', function (): void {
        $dir = codingAgentTempDir('extension-package-local');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);

        $package = createExtensionPackageFixture($dir.'/fixtures/local-package', 'fixture/local-package', ['ext.php'], ['skills'], ['prompts'], ['themes']);
        $manager = new ExtensionPackageManager($dir, $agentDir);

        $project = $manager->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::PROJECT);
        $global = $manager->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::GLOBAL);
        $resolved = $manager->resolveManagedResources();

        expect($project->scope)->toBe(ExtensionPackageScope::PROJECT);
        expect($global->scope)->toBe(ExtensionPackageScope::GLOBAL);
        expect($resolved->extensionPaths)->toContain($project->installedPath.'/ext.php');
        expect($resolved->skillPaths)->toContain($project->installedPath.'/skills');
        expect($resolved->promptPaths)->toContain($project->installedPath.'/prompts');
        expect($resolved->themePaths)->toContain($project->installedPath.'/themes');

        putenv('PI_CODING_AGENT_DIR');
        codingAgentDeleteDir($dir);
    });

    it('installs git-backed packages and records the installed path', function (): void {
        $dir = codingAgentTempDir('extension-package-git');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);

        $package = createExtensionPackageFixture($dir.'/fixtures/git-package', 'fixture/git-package', ['index.php']);
        exec(sprintf('git init %s >/dev/null 2>&1', escapeshellarg($package)));
        exec(sprintf('git -C %s config user.email %s', escapeshellarg($package), escapeshellarg('test@example.com')));
        exec(sprintf('git -C %s config user.name %s', escapeshellarg($package), escapeshellarg('Test User')));
        exec(sprintf('git -C %s add .', escapeshellarg($package)));
        exec(sprintf('git -C %s commit -m %s >/dev/null 2>&1', escapeshellarg($package), escapeshellarg('init')));

        $manager = new ExtensionPackageManager($dir, $agentDir);
        $record = $manager->install(ExtensionPackageSourceType::GIT, $package, ExtensionPackageScope::PROJECT);

        expect(is_dir($record->installedPath))->toBeTrue();
        expect($record->sourceType)->toBe(ExtensionPackageSourceType::GIT);
        expect(is_file($record->installedPath.'/index.php'))->toBeTrue();

        putenv('PI_CODING_AGENT_DIR');
        codingAgentDeleteDir($dir);
    });

    it('enables disables removes and updates managed packages', function (): void {
        $dir = codingAgentTempDir('extension-package-lifecycle');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);

        $package = createExtensionPackageFixture($dir.'/fixtures/package', 'fixture/package', ['index.php']);
        $manager = new ExtensionPackageManager($dir, $agentDir);
        $record = $manager->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::PROJECT);

        $disabled = $manager->setEnabled($record->id, false, ExtensionPackageScope::PROJECT);
        expect($disabled->enabled)->toBeFalse();
        expect($manager->resolveManagedResources()->extensionPaths)->toBe([]);

        $enabled = $manager->setEnabled($record->id, true, ExtensionPackageScope::PROJECT);
        expect($enabled->enabled)->toBeTrue();

        file_put_contents($package.'/index.php', "<?php\n\nreturn function (\$api): void {\n    \$api->registerCommand('updated-ext', 'Updated', fn (string \$args): string => 'updated '.\$args);\n};\n");
        $updated = $manager->update($record->id, ExtensionPackageScope::PROJECT);
        expect(is_file($updated->installedPath.'/index.php'))->toBeTrue();
        expect((string) file_get_contents($updated->installedPath.'/index.php'))->toContain('updated-ext');

        $manager->remove($record->id, ExtensionPackageScope::PROJECT);
        expect($manager->listInstalledPackages(ExtensionPackageScope::PROJECT))->toBe([]);

        putenv('PI_CODING_AGENT_DIR');
        codingAgentDeleteDir($dir);
    });

    it('merges managed packages into extension discovery while keeping unmanaged sources working', function (): void {
        $dir = codingAgentTempDir('extension-package-discovery');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);
        mkdir($dir.'/.pi/extensions', 0777, true);
        file_put_contents($dir.'/.pi/extensions/unmanaged.php', <<<'PHP'
<?php

return function ($api): void {
    $api->registerCommand('unmanaged-ext', 'Unmanaged', fn (string $args): string => 'unmanaged '.$args);
};
PHP);

        $package = createExtensionPackageFixture($dir.'/fixtures/managed-package', 'fixture/managed-package', ['src/managed.php']);
        $manager = new ExtensionPackageManager($dir, $agentDir);
        $manager->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::PROJECT);

        $loadResult = (new ExtensionLoader)->discover($dir, SettingsManager::create($dir, $agentDir));

        expect(array_map(static fn ($extension): string => $extension->resolvedPath, $loadResult->extensions))
            ->toContain($dir.'/.pi/extensions/unmanaged.php')
            ->toContain($dir.'/.pi/packages/fixture-managed-package/src/managed.php');

        putenv('PI_CODING_AGENT_DIR');
        codingAgentDeleteDir($dir);
    });

    it('feeds managed package resources into the filesystem resource loader', function (): void {
        $dir = codingAgentTempDir('extension-package-resources');
        $agentDir = $dir.'/.agent';
        putenv('PI_CODING_AGENT_DIR='.$agentDir);

        $package = createExtensionPackageFixture(
            $dir.'/fixtures/resource-package',
            'fixture/resource-package',
            ['index.php'],
            ['skills'],
            ['prompts'],
            ['themes'],
        );
        $manager = new ExtensionPackageManager($dir, $agentDir);
        $resolved = $manager->install(ExtensionPackageSourceType::LOCAL, $package, ExtensionPackageScope::PROJECT);

        $loader = new FilesystemResourceLoader(cwd: $dir, settingsManager: SettingsManager::create($dir, $agentDir));
        $managed = $manager->resolveManagedResources();
        $loader->extendResources($managed->skillPaths, $managed->promptPaths, $managed->themePaths);

        expect(array_map(static fn (object $skill): string => $skill->path, $loader->loadSkills($dir)))
            ->toContain($resolved->installedPath.'/skills/debug.md');
        expect(array_map(static fn (object $prompt): string => $prompt->path, $loader->loadPromptTemplates($dir)))
            ->toContain($resolved->installedPath.'/prompts/review.md');

        putenv('PI_CODING_AGENT_DIR');
        codingAgentDeleteDir($dir);
    });
});
