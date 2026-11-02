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

use mindstellar\api\routing\Router;

/**
 * Plugin component schemas from the `api_schemas` filter, name => schema. A name must be
 * `Ext<StudlyCaps>` and a schema may `$ref` only plugin components and Router::SHARED_COMPONENTS;
 * anything else is dropped and logged.
 */
final class ExtensionSchemas
{
    /** @api A plugin component's name. */
    public const NAME = '/^Ext[A-Z][A-Za-z0-9]*$/D';

    private function __construct()
    {
    }

    /**
     * @param callable|null $log receives each refusal; error_log() by default
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fromHooks(?callable $log = null): array
    {
        return self::check((array) osc_apply_filter('api_schemas', []), $log);
    }

    /**
     * @param array<mixed,mixed> $schemas
     *
     * @return array<string,array<string,mixed>>
     */
    public static function check(array $schemas, ?callable $log = null): array
    {
        $log  = \Closure::fromCallable($log ?? 'error_log');
        $kept = [];
        foreach ($schemas as $name => $schema) {
            if (is_string($name) && preg_match(self::NAME, $name) === 1 && is_array($schema)) {
                $kept[$name] = $schema;
            } else {
                $log('API schema ' . $name . ' refused: a plugin component is named Ext<StudlyCaps> and is an object.');
            }
        }
        $validator = new Validator(Definitions::lazy([...array_keys($kept), ...Router::SHARED_COMPONENTS], static fn (): array => []));
        foreach ($kept as $name => $schema) {
            $problems = $validator->schemaProblems($schema);
            preg_match_all('~"\$ref":"' . preg_quote(Validator::REF_PREFIX, '~') . '([^"]*)"~', (string) json_encode($schema, JSON_UNESCAPED_SLASHES), $m);
            foreach (array_unique($m[1]) as $ref) {
                if (!isset($kept[$ref]) && !in_array($ref, Router::SHARED_COMPONENTS, true)) {
                    $problems[] = 'core component ' . $ref . ' is not part of the plugin contract';
                }
            }
            if ($problems !== []) {
                $log('API schema ' . $name . ' refused: ' . implode('; ', array_unique($problems)) . '.');
                unset($kept[$name]);
            }
        }

        return $kept;
    }
}
