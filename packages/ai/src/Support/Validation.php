<?php

declare(strict_types=1);

namespace Pi\AI\Support;

use Pi\AI\Content\ToolCall;
use Pi\AI\Schema\Schema;
use Pi\AI\Tool;

final class Validation
{
    /**
     * @param  array<Tool>  $tools
     * @return array<string, mixed>
     */
    public static function validateToolCall(array $tools, ToolCall $toolCall): array
    {
        foreach ($tools as $tool) {
            if ($tool->name === $toolCall->name) {
                return self::validateToolArguments($tool, $toolCall);
            }
        }

        throw new \RuntimeException(sprintf('Tool "%s" not found', $toolCall->name));
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateToolArguments(Tool $tool, ToolCall $toolCall): array
    {
        $schema = self::normalizeSchema($tool->parameters);
        $arguments = self::coerceValue($toolCall->arguments, $schema);
        $errors = self::validateValue($arguments, $schema);

        if ($errors === []) {
            return $arguments;
        }

        $formattedErrors = implode("\n", array_map(static fn (string $error): string => "  - {$error}", $errors));

        throw new \RuntimeException(sprintf(
            "Validation failed for tool \"%s\":\n%s\n\nReceived arguments:\n%s",
            $toolCall->name,
            $formattedErrors,
            json_encode($toolCall->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ));
    }

    /**
     * @param  Schema|array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function normalizeSchema(Schema|array $schema): array
    {
        return $schema instanceof Schema ? $schema->toArray() : $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function coerceValue(mixed $value, array $schema): mixed
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            return self::coerceUnion($value, $schema['anyOf']);
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            return self::coerceUnion($value, $schema['oneOf']);
        }

        $types = self::schemaTypes($schema);
        if (count($types) > 1 && self::matchesAnyType($value, $types)) {
            return self::coerceNestedValue($value, $schema, $types);
        }

        foreach ($types as $type) {
            $candidate = self::coercePrimitive($value, $type);
            if ($candidate !== $value || self::matchesType($candidate, $type)) {
                $value = $candidate;
                break;
            }
        }

        return self::coerceNestedValue($value, $schema, $types);
    }

    /**
     * @param  list<array<string, mixed>>  $schemas
     */
    private static function coerceUnion(mixed $value, array $schemas): mixed
    {
        foreach ($schemas as $schema) {
            $candidate = self::coerceValue($value, $schema);
            if (self::validateValue($candidate, $schema) === []) {
                return $candidate;
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $types
     */
    private static function coerceNestedValue(mixed $value, array $schema, array $types): mixed
    {
        if (in_array('object', $types, true) && self::isRecord($value)) {
            $properties = $schema['properties'] ?? [];
            foreach ($properties as $key => $propertySchema) {
                if (array_key_exists($key, $value) && is_array($propertySchema)) {
                    $value[$key] = self::coerceValue($value[$key], $propertySchema);
                }
            }

            $additionalProperties = $schema['additionalProperties'] ?? null;
            if (is_array($additionalProperties)) {
                foreach ($value as $key => $propertyValue) {
                    if (! array_key_exists($key, $properties)) {
                        $value[$key] = self::coerceValue($propertyValue, $additionalProperties);
                    }
                }
            }
        }

        if (in_array('array', $types, true) && is_array($value) && array_is_list($value)) {
            $items = $schema['items'] ?? null;
            if (is_array($items) && array_is_list($items)) {
                foreach ($items as $index => $itemSchema) {
                    if (array_key_exists($index, $value) && is_array($itemSchema)) {
                        $value[$index] = self::coerceValue($value[$index], $itemSchema);
                    }
                }
            } elseif (is_array($items)) {
                foreach ($value as $index => $itemValue) {
                    $value[$index] = self::coerceValue($itemValue, $items);
                }
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function schemaTypes(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (is_array($type)) {
            return array_values(array_filter($type, static fn (mixed $value): bool => is_string($value)));
        }

        return [];
    }

    private static function coercePrimitive(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number' => self::coerceNumber($value, false),
            'integer' => self::coerceNumber($value, true),
            'boolean' => self::coerceBoolean($value),
            'string' => self::coerceString($value),
            'null' => self::coerceNull($value),
            default => $value,
        };
    }

    private static function coerceNumber(mixed $value, bool $integer): mixed
    {
        if ($value === null) {
            return 0;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value) && trim($value) !== '') {
            $parsed = str_contains($value, '.') ? (float) $value : (int) $value;
            if ($integer && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                return (int) $parsed;
            }

            if (! $integer && is_numeric($value)) {
                return (float) $value;
            }
        }

        return $value;
    }

    private static function coerceBoolean(mixed $value): mixed
    {
        return match (true) {
            $value === null => false,
            $value === 'true' => true,
            $value === 'false' => false,
            $value === 1 => true,
            $value === 0 => false,
            default => $value,
        };
    }

    private static function coerceString(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $value;
    }

    private static function coerceNull(mixed $value): mixed
    {
        if ($value === '' || $value === 0 || $value === false) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validateValue(mixed $value, array $schema, string $path = 'root'): array
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $candidate) {
                if (is_array($candidate) && self::validateValue($value, $candidate, $path) === []) {
                    return [];
                }
            }

            return [sprintf('%s: value does not match any allowed schema', $path)];
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $candidate) {
                if (is_array($candidate) && self::validateValue($value, $candidate, $path) === []) {
                    $matches++;
                }
            }

            return $matches === 1 ? [] : [sprintf('%s: value must match exactly one schema', $path)];
        }

        $errors = [];
        $types = self::schemaTypes($schema);
        if ($types !== [] && ! self::matchesAnyType($value, $types)) {
            $errors[] = sprintf('%s: expected %s', $path, implode('|', $types));

            return $errors;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf('%s: value is not one of the allowed enum values', $path);
        }

        if (in_array('object', $types, true) && self::isRecord($value)) {
            $required = $schema['required'] ?? [];
            foreach ($required as $requiredKey) {
                if (! array_key_exists($requiredKey, $value)) {
                    $errors[] = sprintf('%s.%s: is required', $path, $requiredKey);
                }
            }

            $properties = $schema['properties'] ?? [];
            foreach ($properties as $key => $propertySchema) {
                if (array_key_exists($key, $value) && is_array($propertySchema)) {
                    array_push($errors, ...self::validateValue($value[$key], $propertySchema, sprintf('%s.%s', $path, $key)));
                }
            }
        }

        if (in_array('array', $types, true) && is_array($value) && array_is_list($value)) {
            $items = $schema['items'] ?? null;
            if (is_array($items) && array_is_list($items)) {
                foreach ($items as $index => $itemSchema) {
                    if (array_key_exists($index, $value) && is_array($itemSchema)) {
                        array_push($errors, ...self::validateValue($value[$index], $itemSchema, sprintf('%s.%d', $path, $index)));
                    }
                }
            } elseif (is_array($items)) {
                foreach ($value as $index => $itemValue) {
                    array_push($errors, ...self::validateValue($itemValue, $items, sprintf('%s.%d', $path, $index)));
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $types
     */
    private static function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if (self::matchesType($value, $type)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'null' => $value === null,
            'array' => is_array($value) && array_is_list($value),
            'object' => self::isRecord($value),
            default => false,
        };
    }

    private static function isRecord(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }
}
