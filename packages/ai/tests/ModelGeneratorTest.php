<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';
require_once aiPackageRoot('src/ModelCatalog.php');

use Pi\AI\ModelCatalog;

describe('model generator', function () {
    it('keeps the generated catalog in sync with the seed generator', function () {
        $expected = ModelCatalog::seed();
        $actual = require aiPackageRoot('src/models.generated.php');

        expect($actual)->toBe($expected);
    });

    it('exposes the seeded providers needed for the current MVP', function () {
        $seed = ModelCatalog::seed();

        expect($seed)->toHaveKey('openai');
        expect($seed)->toHaveKey('anthropic');
        expect($seed)->toHaveKey('openrouter');
        expect($seed)->toHaveKey('openai-codex');
        expect($seed['openai'])->toHaveKey('gpt-5-mini');
        expect($seed['anthropic'])->toHaveKey('claude-opus-4-6');
    });
});
