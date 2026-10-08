<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\api\schema;

use mindstellar\utility\DateInput;

/**
 * A JSON Schema subset validator. The same schema arrays describe the API in OpenAPI, so a
 * schema is checked once, when its route is built (schemaProblems()), and check() trusts it.
 */
final class Validator
{
    /** Keywords that are checked. */
    public const ASSERTIONS = [
        'type', 'required', 'enum', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'format',
        'items', 'minItems', 'maxItems', 'properties', 'additionalProperties', '$ref',
    ];

    /** Keywords that only describe. */
    public const ANNOTATIONS = ['title', 'description', 'default', 'example', 'examples', 'deprecated', 'readOnly', 'writeOnly'];

    public const TYPES = ['string', 'integer', 'number', 'boolean', 'null', 'array', 'object'];

    public const FORMATS = ['email', 'uri', 'date-time'];

    public const REF_PREFIX = '#/components/schemas/';

    private Definitions $definitions;

    /**
     * @param array<string,array<string,mixed>>|Definitions $definitions named schemas a `$ref` can point at
     */
    public function __construct(array|Definitions $definitions = [])
    {
        $this->definitions = is_array($definitions) ? Definitions::of($definitions) : $definitions;
    }

    /**
     * Every way $value breaks $schema. The schema must have passed schemaProblems().
     *
     * @param array<string,mixed> $schema
     * @param string              $pointer JSON Pointer of $value in the document
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function check(array $schema, mixed $value, string $pointer = ''): array
    {
        if (isset($schema['$ref'])) {
            return $this->check($this->definitions->get(self::refName((string) $schema['$ref'])), $value, $pointer);
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok    = false;
            foreach ($types as $type) {
                if (self::isType($value, (string) $type)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return [self::error($pointer, 'type', 'must be ' . self::typeWords($types))];
            }
        }

        $errors = [];
        if (isset($schema['enum']) && !in_array($value, (array) $schema['enum'], true)) {
            $errors[] = self::error($pointer, 'enum', 'must be one of: ' . implode(', ', array_map('strval', (array) $schema['enum'])));
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = self::error($pointer, 'minimum', 'must be at least ' . $schema['minimum']);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = self::error($pointer, 'maximum', 'must be at most ' . $schema['maximum']);
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $errors[] = self::error($pointer, 'minLength', 'must be at least ' . $schema['minLength'] . ' characters');
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $errors[] = self::error($pointer, 'maxLength', 'must be at most ' . $schema['maxLength'] . ' characters');
            }
            if (isset($schema['pattern']) && preg_match(self::patternRegex((string) $schema['pattern']), $value) !== 1) {
                $errors[] = self::error($pointer, 'pattern', 'does not have the expected form');
            }
            if (isset($schema['format']) && !self::isFormat($value, (string) $schema['format'])) {
                $errors[] = self::error($pointer, 'format', 'must be a valid ' . $schema['format']);
            }
        }

        if (is_array($value) && self::isList($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = self::error($pointer, 'minItems', 'must have at least ' . $schema['minItems'] . ' items');
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = self::error($pointer, 'maxItems', 'must have at most ' . $schema['maxItems'] . ' items');
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...$this->check($schema['items'], $item, $pointer . '/' . $i));
                }
            }
        }

        if (is_array($value) && ($value === [] || !self::isList($value))) {
            array_push($errors, ...$this->checkObject($schema, $value, $pointer));
        }

        return $errors;
    }

    /**
     * Query values arrive as strings. Turn the ones whose schema says integer, number or
     * boolean into those types, so check() can judge them; anything unparseable is left as
     * it is and fails the type check.
     *
     * @param array<string,mixed> $schema an object schema
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    public function coerceQuery(array $schema, array $query): array
    {
        if (isset($schema['$ref'])) {
            $schema = $this->definitions->get(self::refName((string) $schema['$ref']));
        }
        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            if (!is_string($query[$name] ?? null) || !isset($property['type'])) {
                continue;
            }
            $raw   = strtolower(trim($query[$name]));
            $types = (array) $property['type'];
            if (in_array('integer', $types, true) && preg_match('/^-?[0-9]{1,18}$/', $raw) === 1) {
                $query[$name] = (int) $raw;
            } elseif (in_array('number', $types, true) && is_numeric($raw)) {
                $query[$name] = (float) $raw;
            } elseif (in_array('boolean', $types, true) && in_array($raw, ['1', 'true', '0', 'false'], true)) {
                $query[$name] = $raw === '1' || $raw === 'true';
            }
        }

        return $query;
    }

    /**
     * What is wrong with a schema itself, at any depth: keywords, types or formats outside the
     * subset, and `$ref`s that do not resolve. Empty when check() can use it.
     *
     * @param array<string,mixed> $schema
     *
     * @return string[]
     */
    public function schemaProblems(array $schema): array
    {
        $problems = [];
        foreach (array_diff(array_keys($schema), self::ASSERTIONS, self::ANNOTATIONS) as $keyword) {
            $problems[] = 'unsupported keyword ' . $keyword;
        }
        foreach ((array) ($schema['type'] ?? []) as $type) {
            if (!in_array($type, self::TYPES, true)) {
                $problems[] = 'unsupported type ' . $type;
            }
        }
        if (isset($schema['format']) && !in_array($schema['format'], self::FORMATS, true)) {
            $problems[] = 'unsupported format ' . $schema['format'];
        }
        if (isset($schema['pattern']) && @preg_match(self::patternRegex((string) $schema['pattern']), '') === false) {
            $problems[] = 'invalid pattern ' . $schema['pattern'];
        }
        if (in_array('array', (array) ($schema['type'] ?? []), true) && !isset($schema['items'])) {
            // Code generators need the element type.
            $problems[] = 'an array needs items';
        }
        if (isset($schema['$ref']) && !$this->definitions->has(self::refName((string) $schema['$ref']))) {
            $problems[] = 'unknown reference ' . $schema['$ref'];
        }

        $children = (array) ($schema['properties'] ?? []);
        foreach (['items', 'additionalProperties'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $children[] = $schema[$keyword];
            }
        }
        foreach ($children as $child) {
            array_push($problems, ...(is_array($child) ? $this->schemaProblems($child) : ['a schema must be an object']));
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<mixed>        $value
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    private function checkObject(array $schema, array $value, string $pointer): array
    {
        $errors = [];
        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (!array_key_exists($name, $value)) {
                $errors[] = self::error($pointer . '/' . self::escape((string) $name), 'required', 'is required');
            }
        }
        $properties = (array) ($schema['properties'] ?? []);
        $extra      = $schema['additionalProperties'] ?? true;
        foreach ($value as $name => $item) {
            $at = $pointer . '/' . self::escape((string) $name);
            if (isset($properties[$name])) {
                array_push($errors, ...$this->check($properties[$name], $item, $at));
            } elseif ($extra === false) {
                $errors[] = self::error($at, 'additionalProperties', 'is not a known field');
            } elseif (is_array($extra)) {
                array_push($errors, ...$this->check($extra, $item, $at));
            }
        }

        return $errors;
    }

    private static function refName(string $ref): string
    {
        return str_starts_with($ref, self::REF_PREFIX) ? substr($ref, strlen(self::REF_PREFIX)) : '';
    }

    private static function patternRegex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~u';
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value && abs($value) < 2 ** 53),
            'number'  => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null'    => $value === null,
            'array'   => is_array($value) && self::isList($value),
            // A decoded {} and [] are both an empty PHP array.
            'object'  => is_array($value) && ($value === [] || !self::isList($value)),
            default   => false,
        };
    }

    private static function isFormat(string $value, string $format): bool
    {
        return match ($format) {
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri'       => filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true),
            'date-time' => !DateInput::isDay($value) && DateInput::parse($value) !== null,
            default     => false,
        };
    }

    /**
     * @param array<int,mixed> $types
     */
    private static function typeWords(array $types): string
    {
        $words = [];
        foreach ($types as $type) {
            $words[] = match ($type) {
                'array', 'object', 'integer' => 'an ' . $type,
                'null'                       => 'null',
                default                      => 'a ' . $type,
            };
        }

        return implode(' or ', $words);
    }

    /**
     * array_is_list() arrived in PHP 8.1.
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $i = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $i++) {
                return false;
            }
        }

        return true;
    }

    private static function escape(string $name): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $name);
    }

    /**
     * @return array{pointer:string,code:string,message:string}
     */
    private static function error(string $pointer, string $code, string $message): array
    {
        return ['pointer' => $pointer, 'code' => $code, 'message' => $message];
    }
}
