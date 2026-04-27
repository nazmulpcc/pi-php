<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use function Pi\AI\loadGeneratedModels;
use function Pi\AI\packageRoot;

describe('AI package scaffold', function () {
    it('autoloads package helper functions', function () {
        expect(function_exists('Pi\\AI\\packageRoot'))->toBeTrue();
        expect(function_exists('Pi\\AI\\loadGeneratedModels'))->toBeTrue();
    });

    it('resolves package-relative paths', function () {
        expect(packageRoot())->toEndWith('/packages/ai');
        expect(is_file(aiPackageRoot('composer.json')))->toBeTrue();
        expect(is_dir(aiPackageRoot('tests')))->toBeTrue();
    });

    it('loads the generated model catalog seed', function () {
        $models = loadGeneratedModels();

        expect($models)->toHaveKey('openai');
        expect($models['openai'])->toHaveKey('gpt-5-mini');
    });
});
