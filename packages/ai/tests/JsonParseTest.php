<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use function Pi\AI\parseJsonWithRepair;
use function Pi\AI\parseStreamingJson;
use function Pi\AI\repairJson;

describe('json parse helpers', function () {
    it('repairs malformed string escapes and control characters', function () {
        $broken = "{\"text\":\"hello\nworld\\q\"}";

        expect(repairJson($broken))->toBe('{"text":"hello\\nworld\\\\q"}');
        expect(parseJsonWithRepair($broken))->toBe(['text' => "hello\nworld\\q"]);
    });

    it('parses partial streaming json into best-effort objects', function () {
        expect(parseStreamingJson('{"path":"README.md"'))->toBe(['path' => 'README.md']);
        expect(parseStreamingJson('{"path":"README.md","content":"updated"}'))->toBe([
            'path' => 'README.md',
            'content' => 'updated',
        ]);
        expect(parseStreamingJson(''))->toBe([]);
    });
});
