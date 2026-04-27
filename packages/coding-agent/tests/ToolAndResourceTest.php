<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\CodingAgent\Resource\FilesystemResourceLoader;
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
});
