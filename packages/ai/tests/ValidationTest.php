<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Content\ToolCall;
use Pi\AI\Schema\Type;
use Pi\AI\Tool;

use function Pi\AI\validateToolArguments;

function createToolCallWithPlainSchema(array $schema, mixed $value): array
{
    $tool = new Tool(
        name: 'echo',
        description: 'Echo tool',
        parameters: [
            'type' => 'object',
            'properties' => [
                'value' => $schema,
            ],
            'required' => ['value'],
        ],
    );

    $toolCall = new ToolCall(
        id: 'tool-1',
        name: 'echo',
        arguments: ['value' => $value],
    );

    return [$tool, $toolCall];
}

describe('validateToolArguments', function () {
    it('coerces builder-authored schemas', function () {
        $tool = new Tool(
            name: 'echo',
            description: 'Echo tool',
            parameters: Type::object([
                'count' => Type::number(),
            ]),
        );

        $toolCall = new ToolCall(
            id: 'tool-1',
            name: 'echo',
            arguments: ['count' => '42'],
        );

        expect(validateToolArguments($tool, $toolCall))->toBe(['count' => 42.0]);
    });

    it('coerces serialized plain json schemas with primitive rules', function () {
        $passingCases = [
            [['type' => 'number'], '42', 42.0],
            [['type' => 'number'], true, 1],
            [['type' => 'number'], null, 0],
            [['type' => 'integer'], '42', 42],
            [['type' => 'boolean'], 'true', true],
            [['type' => 'boolean'], 'false', false],
            [['type' => 'boolean'], 1, true],
            [['type' => 'boolean'], 0, false],
            [['type' => 'string'], null, ''],
            [['type' => 'string'], true, '1'],
            [['type' => 'null'], '', null],
            [['type' => 'null'], 0, null],
            [['type' => 'null'], false, null],
            [['type' => ['number', 'string']], '1', '1'],
            [['type' => ['boolean', 'number']], '1', 1.0],
        ];

        foreach ($passingCases as [$schema, $input, $expected]) {
            [$tool, $toolCall] = createToolCallWithPlainSchema($schema, $input);
            expect(validateToolArguments($tool, $toolCall))->toBe(['value' => $expected]);
        }
    });

    it('rejects invalid coercions for serialized plain json schemas', function () {
        $failingCases = [
            [['type' => 'boolean'], '1'],
            [['type' => 'boolean'], '0'],
            [['type' => 'null'], 'null'],
            [['type' => 'integer'], '42.1'],
        ];

        foreach ($failingCases as [$schema, $input]) {
            [$tool, $toolCall] = createToolCallWithPlainSchema($schema, $input);
            expect(fn () => validateToolArguments($tool, $toolCall))->toThrow('Validation failed');
        }
    });
});
