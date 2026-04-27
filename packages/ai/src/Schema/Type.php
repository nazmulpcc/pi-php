<?php

declare(strict_types=1);

namespace Pi\AI\Schema;

final class Type
{
    /**
     * @param  array<string, mixed>  $options
     */
    public static function string(array $options = []): Schema
    {
        return new Schema(array_merge(['type' => 'string'], $options));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function number(array $options = []): Schema
    {
        return new Schema(array_merge(['type' => 'number'], $options));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function integer(array $options = []): Schema
    {
        return new Schema(array_merge(['type' => 'integer'], $options));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function boolean(array $options = []): Schema
    {
        return new Schema(array_merge(['type' => 'boolean'], $options));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function null(array $options = []): Schema
    {
        return new Schema(array_merge(['type' => 'null'], $options));
    }

    /**
     * @param  Schema|array<string, mixed>  $items
     * @param  array<string, mixed>  $options
     */
    public static function array(Schema|array $items, array $options = []): Schema
    {
        return new Schema(array_merge([
            'type' => 'array',
            'items' => self::normalize($items),
        ], $options));
    }

    /**
     * @param  array<string, Schema|array<string, mixed>>  $properties
     * @param  array<string, mixed>  $options
     */
    public static function object(array $properties, array $options = []): Schema
    {
        $normalized = [];
        $required = [];

        foreach ($properties as $name => $schema) {
            $normalized[$name] = self::normalize($schema);
            if (! ($schema instanceof Schema && $schema->isOptional())) {
                $required[] = $name;
            }
        }

        $definition = array_merge([
            'type' => 'object',
            'properties' => $normalized,
        ], $options);

        if ($required !== []) {
            $definition['required'] = $required;
        }

        return new Schema($definition);
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, mixed>  $options
     */
    public static function stringEnum(array $values, array $options = []): Schema
    {
        return new Schema(array_merge([
            'type' => 'string',
            'enum' => array_values($values),
        ], $options));
    }

    /**
     * @param  Schema|array<string, mixed>  $schema
     */
    public static function optional(Schema|array $schema): OptionalSchema
    {
        return new OptionalSchema(self::normalize($schema));
    }

    /**
     * @param  Schema|array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function normalize(Schema|array $schema): array
    {
        return $schema instanceof Schema ? $schema->toArray() : $schema;
    }
}
