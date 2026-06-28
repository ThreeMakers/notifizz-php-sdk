<?php

namespace Notifizz;

/**
 * Dependency-free JSON Schema validator covering the keyword subset the SDK
 * realistically needs to describe enricher input and event payloads: `type`
 * (incl. type arrays / nullable), `required`, `properties`,
 * `additionalProperties:false`, `enum`, `const`, `minLength`/`maxLength`,
 * `pattern`, `minimum`/`maximum`, `exclusiveMinimum`/`exclusiveMaximum`,
 * `multipleOf`, `minItems`/`maxItems`, and `items`.
 *
 * Validates plain decoded values, collects all errors (never throws on a miss),
 * and applies no defaults — matching the Node SDK's raw-JSON-Schema (Ajv
 * `useDefaults:false`) path. Unknown keywords are ignored (no opinion).
 *
 * @internal
 */
final class JsonSchemaValidator
{
    /**
     * @return string[] empty when $value conforms to $schema.
     */
    public static function validate(array $schema, $value): array
    {
        $errors = [];
        self::node($schema, $value, '', $errors);
        return $errors;
    }

    private static function node($schema, $value, string $path, array &$errors): void
    {
        if (!is_array($schema)) {
            return; // boolean / unrecognised schema → no opinion
        }

        if (isset($schema['type']) && !self::matchesType($schema['type'], $value)) {
            $errors[] = self::at($path) . 'expected type ' . self::typeDesc($schema['type']) . ' but got ' . self::typeName($value);
            return; // downstream keyword checks assume the type held
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $ok = false;
            foreach ($schema['enum'] as $allowed) {
                if (self::eq($allowed, $value)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = self::at($path) . 'value is not one of the allowed enum values';
            }
        }
        if (array_key_exists('const', $schema) && !self::eq($schema['const'], $value)) {
            $errors[] = self::at($path) . 'value does not equal the required const';
        }

        if (is_string($value)) {
            $len = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if (isset($schema['minLength']) && $len < $schema['minLength']) {
                $errors[] = self::at($path) . 'string shorter than minLength ' . $schema['minLength'];
            }
            if (isset($schema['maxLength']) && $len > $schema['maxLength']) {
                $errors[] = self::at($path) . 'string longer than maxLength ' . $schema['maxLength'];
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $re = '/' . str_replace('/', '\\/', $schema['pattern']) . '/';
                if (@preg_match($re, $value) === 0) {
                    $errors[] = self::at($path) . 'string does not match pattern ' . $schema['pattern'];
                }
            }
        }

        if ((is_int($value) || is_float($value)) && !is_bool($value)) {
            $d = (float) $value;
            if (isset($schema['minimum']) && $d < $schema['minimum']) {
                $errors[] = self::at($path) . 'number below minimum ' . $schema['minimum'];
            }
            if (isset($schema['maximum']) && $d > $schema['maximum']) {
                $errors[] = self::at($path) . 'number above maximum ' . $schema['maximum'];
            }
            if (isset($schema['exclusiveMinimum']) && $d <= $schema['exclusiveMinimum']) {
                $errors[] = self::at($path) . 'number not above exclusiveMinimum ' . $schema['exclusiveMinimum'];
            }
            if (isset($schema['exclusiveMaximum']) && $d >= $schema['exclusiveMaximum']) {
                $errors[] = self::at($path) . 'number not below exclusiveMaximum ' . $schema['exclusiveMaximum'];
            }
            if (isset($schema['multipleOf']) && $schema['multipleOf'] != 0) {
                $r = $d / (float) $schema['multipleOf'];
                if (abs($r - round($r)) > 1e-9) {
                    $errors[] = self::at($path) . 'number not a multiple of ' . $schema['multipleOf'];
                }
            }
        }

        // Array keywords — also runs for the empty array (ambiguous {} / [] in PHP),
        // but acts only on keywords an array schema actually declares.
        if (is_array($value) && (self::isList($value) || count($value) === 0)) {
            $n = count($value);
            if (isset($schema['minItems']) && $n < $schema['minItems']) {
                $errors[] = self::at($path) . 'array shorter than minItems ' . $schema['minItems'];
            }
            if (isset($schema['maxItems']) && $n > $schema['maxItems']) {
                $errors[] = self::at($path) . 'array longer than maxItems ' . $schema['maxItems'];
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    self::node($schema['items'], $item, $path . '[' . $i . ']', $errors);
                }
            }
        }

        // Object keywords — also runs for the empty array (which may be an empty {}).
        if (is_array($value) && (!self::isList($value) || count($value) === 0)) {
            if (isset($schema['required']) && is_array($schema['required'])) {
                foreach ($schema['required'] as $r) {
                    if (is_string($r) && !array_key_exists($r, $value)) {
                        $errors[] = self::at($path) . "missing required property '" . $r . "'";
                    }
                }
            }
            if (isset($schema['properties']) && is_array($schema['properties'])) {
                foreach ($schema['properties'] as $k => $propSchema) {
                    if (array_key_exists($k, $value)) {
                        self::node($propSchema, $value[$k], self::child($path, (string) $k), $errors);
                    }
                }
                if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
                    foreach (array_keys($value) as $k) {
                        if (!array_key_exists($k, $schema['properties'])) {
                            $errors[] = self::at($path) . "unexpected additional property '" . $k . "'";
                        }
                    }
                }
            }
        }
    }

    private static function matchesType($type, $value): bool
    {
        if (is_array($type)) {
            foreach ($type as $t) {
                if (self::matchesSingle((string) $t, $value)) {
                    return true;
                }
            }
            return false;
        }
        return self::matchesSingle((string) $type, $value);
    }

    private static function matchesSingle(string $type, $value): bool
    {
        switch ($type) {
            case 'object':
                return is_array($value) && (!self::isList($value) || count($value) === 0);
            case 'array':
                return is_array($value) && (self::isList($value) || count($value) === 0);
            case 'string':
                return is_string($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            case 'number':
                return (is_int($value) || is_float($value)) && !is_bool($value);
            case 'integer':
                return is_int($value) || (is_float($value) && !is_infinite($value) && floor($value) === $value);
            default:
                return true; // unknown type keyword → no opinion
        }
    }

    private static function isList(array $a): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($a);
        }
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }

    private static function eq($a, $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) && !is_bool($a) && !is_bool($b)) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }

    private static function typeName($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v)) {
            return 'integer';
        }
        if (is_float($v)) {
            return 'number';
        }
        if (is_string($v)) {
            return 'string';
        }
        if (is_array($v)) {
            return self::isList($v) ? 'array' : 'object';
        }
        return gettype($v);
    }

    /** @param string|string[] $type */
    private static function typeDesc($type): string
    {
        return is_array($type) ? implode('|', $type) : (string) $type;
    }

    private static function at(string $path): string
    {
        return $path === '' ? '' : $path . ': ';
    }

    private static function child(string $path, string $key): string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }
}
