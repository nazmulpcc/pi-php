<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\CodingAgent\Resource\FilesystemResourceLoader;
use Pi\CodingAgent\Settings\SettingsManager;
use Pi\CodingAgent\Tool\BashTool;
use Pi\CodingAgent\Tool\EditTool;
use Pi\CodingAgent\Tool\FindTool;
use Pi\CodingAgent\Tool\GrepTool;
use Pi\CodingAgent\Tool\LsTool;
use Pi\CodingAgent\Tool\ReadTool;
use Pi\CodingAgent\Tool\WriteTool;

describe('Coding agent tools and resources', function () {
    it('executes built-in file and bash tools', function () {
        $dir = codingAgentTempDir();

        $write = new WriteTool($dir);
        codingAgentBlock($write->execute('call-1', [
            'path' => 'notes.txt',
            'content' => "first line\nsecond line",
        ]));

        $read = new ReadTool($dir);
        $readResult = codingAgentBlock($read->execute('call-2', ['path' => 'notes.txt']));
        expect($readResult->content[0]->text)->toContain('second line');

        $edit = new EditTool($dir);
        codingAgentBlock($edit->execute('call-3', [
            'path' => 'notes.txt',
            'search' => 'second',
            'replace' => 'updated',
        ]));

        $grep = new GrepTool($dir);
        $grepResult = codingAgentBlock($grep->execute('call-4', [
            'path' => '.',
            'pattern' => 'updated',
        ]));
        expect($grepResult->content[0]->text)->toContain('notes.txt:2:updated line');

        $find = new FindTool($dir);
        $findResult = codingAgentBlock($find->execute('call-5', [
            'path' => '.',
            'pattern' => 'notes',
        ]));
        expect($findResult->content[0]->text)->toContain('notes.txt');

        $ls = new LsTool($dir);
        $lsResult = codingAgentBlock($ls->execute('call-6', ['path' => '.']));
        expect($lsResult->content[0]->text)->toContain('notes.txt');

        $bash = new BashTool($dir);
        $bashResult = codingAgentBlock($bash->execute('call-7', [
            'command' => PHP_OS_FAMILY === 'Windows' ? 'php -r "echo 123;"' : 'printf 123',
        ]));
        expect($bashResult->content[0]->text)->toContain('123');

        codingAgentDeleteDir($dir);
    });

    it('loads context files, skills, and prompt templates from disk', function () {
        $dir = codingAgentTempDir();
        mkdir($dir.'/.pi/skills', 0777, true);
        mkdir($dir.'/.pi/prompts', 0777, true);
        file_put_contents($dir.'/AGENTS.md', 'Project instructions');
        file_put_contents($dir.'/.pi/skills/debug.md', 'Debug skill');
        file_put_contents($dir.'/.pi/prompts/review.md', 'Review template');

        $loader = new FilesystemResourceLoader;

        $contextFiles = $loader->loadContextFiles($dir);
        $skills = $loader->loadSkills($dir);
        $templates = $loader->loadPromptTemplates($dir);

        expect($contextFiles)->toHaveCount(1);
        expect($contextFiles[0]->content)->toBe('Project instructions');
        expect($skills)->toHaveCount(1);
        expect($skills[0]->name)->toBe('debug');
        expect($templates)->toHaveCount(1);
        expect($templates[0]->name)->toBe('review');

        codingAgentDeleteDir($dir);
    });

    it('loads richer prompt resources and can reload settings-backed paths', function () {
        $dir = codingAgentTempDir();
        mkdir($dir.'/shared-skills', 0777, true);
        mkdir($dir.'/shared-prompts', 0777, true);
        file_put_contents($dir.'/shared-skills/refactor.md', 'Refactor skill');
        file_put_contents($dir.'/shared-prompts/commit.md', 'Commit template');

        $settings = SettingsManager::inMemory(
            global: [],
            project: [
                'skills' => [$dir.'/shared-skills'],
                'prompts' => [$dir.'/shared-prompts'],
            ],
        );

        $loader = new FilesystemResourceLoader(
            cwd: $dir,
            settingsManager: $settings,
            systemPrompt: 'Base prompt',
            appendSystemPrompt: ['Extra prompt'],
        );

        expect($loader->getSystemPrompt())->toBe('Base prompt');
        expect($loader->getAppendSystemPrompt())->toBe(['Extra prompt']);
        expect(array_map(static fn ($skill): string => $skill->name, $loader->loadSkills($dir)))->toContain('refactor');
        expect(array_map(static fn ($template): string => $template->name, $loader->loadPromptTemplates($dir)))->toContain('commit');

        $settings->setProjectSettings([
            'skills' => [$dir.'/other-skills'],
            'prompts' => [$dir.'/other-prompts'],
        ]);
        mkdir($dir.'/other-skills', 0777, true);
        mkdir($dir.'/other-prompts', 0777, true);
        file_put_contents($dir.'/other-skills/debug.md', 'Debug skill');
        file_put_contents($dir.'/other-prompts/review.md', 'Review template');

        $loader->reload();

        expect(array_map(static fn ($skill): string => $skill->name, $loader->loadSkills($dir)))->toContain('debug');
        expect(array_map(static fn ($template): string => $template->name, $loader->loadPromptTemplates($dir)))->toContain('review');
        expect($loader->getDiagnostics())->toBe([]);

        codingAgentDeleteDir($dir);
    });
});
