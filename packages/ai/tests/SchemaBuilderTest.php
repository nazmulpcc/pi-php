<?php

declare(strict_types=1);

require_once __DIR__.'/TestHelper.php';

use Pi\AI\Schema\Type;

describe('schema builder', function () {
    it('builds serializable object schemas with inferred required fields', function () {
        $schema = Type::object([
            'title' => Type::string(['minLength' => 1]),
            'timezone' => Type::optional(Type::string()),
            'count' => Type::integer(),
        ]);

        expect($schema->toArray())->toBe([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1],
                'timezone' => ['type' => 'string'],
                'count' => ['type' => 'integer'],
            ],
            'required' => ['title', 'count'],
        ]);

        expect(json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
            ->toBe($schema->toArray());
    });

    it('builds string enums and arrays', function () {
        $schema = Type::object([
            'units' => Type::stringEnum(['celsius', 'fahrenheit']),
            'emails' => Type::array(Type::string(['format' => 'email']), ['minItems' => 1]),
        ]);

        expect($schema->toArray()['properties']['units'])->toBe([
            'type' => 'string',
            'enum' => ['celsius', 'fahrenheit'],
        ]);
        expect($schema->toArray()['properties']['emails'])->toBe([
            'type' => 'array',
            'items' => ['type' => 'string', 'format' => 'email'],
            'minItems' => 1,
        ]);
    });
});
