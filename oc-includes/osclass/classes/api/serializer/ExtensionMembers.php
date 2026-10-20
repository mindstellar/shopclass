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

namespace mindstellar\api\serializer;

use mindstellar\api\schema\Validator;

/**
 * The plugin fields declared through osc_api_register_field() (the `api_fields` filter).
 * A declaration with a bad object, slug, name, schema or view is refused and logged.
 */
final class ExtensionMembers
{
    /** @api The objects a plugin field may go on. */
    public const OBJECTS = ['listing', 'user', 'category'];

    /** @api A plugin slug. */
    public const SLUG = '/^[a-z0-9-]+$/D';
    /** @api A plugin field name. */
    public const NAME = '/^[a-z0-9_]+$/D';

    /** @var array<string,array<string,ExtensionMember>> object => 'slug.name' => field */
    private array $fields = [];

    /**
     * @param ExtensionMember[] $fields
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $field) {
            $this->fields[$field->object()][$field->slug() . '.' . $field->name()] = $field;
        }
    }

    /**
     * Every field plugins declared on `api_fields`.
     *
     * @param callable|null $log receives each refusal; error_log() by default
     */
    public static function fromHooks(Validator $validator, ?callable $log = null): self
    {
        $declared = osc_apply_filter('api_fields', []);

        return self::fromDeclarations(is_array($declared) ? $declared : [], $validator, $log);
    }

    /**
     * @param array<int,mixed> $declared each [object, slug, name, schema, views]
     */
    public static function fromDeclarations(array $declared, Validator $validator, ?callable $log = null): self
    {
        $log ??= 'error_log';
        $fields = [];
        foreach ($declared as $entry) {
            $entry = is_array($entry) ? array_values($entry) : [];
            [$object, $slug, $name, $schema, $views] = $entry + ['', '', '', null, null];
            $label = 'API field ' . $object . ' ext.' . $slug . '.' . $name;
            $views = is_array($views) ? array_values(array_map('strval', $views)) : [];
            $why   = match (true) {
                !in_array($object, self::OBJECTS, true)            => 'the object must be listing, user or category',
                !is_string($slug) || !preg_match(self::SLUG, $slug) => 'the slug must be a plugin slug',
                !is_string($name) || !preg_match(self::NAME, $name) => 'the name must be lowercase letters, digits and _',
                !is_array($schema)                                  => 'the schema must be an object',
                $views === [] || array_diff($views, ViewContext::VIEWS) !== [] => 'views must be public, owner or admin',
                default                                             => implode('; ', $validator->schemaProblems($schema)),
            };
            if ($why !== '') {
                $log($label . ' refused: ' . $why . '.');
                continue;
            }
            $fields[] = new ExtensionMember($object, $slug, $name, $schema, $views);
        }

        return new self($fields);
    }

    /**
     * @return ExtensionMember[]
     */
    public function forObject(string $object): array
    {
        return array_values($this->fields[$object] ?? []);
    }

    public function find(string $object, string $slug, string $name): ?ExtensionMember
    {
        return $this->fields[$object][$slug . '.' . $name] ?? null;
    }

    /**
     * The `ext` property of an object's schema: one object per plugin slug.
     *
     * @return array<string,mixed>
     */
    public function schemaFor(string $object): array
    {
        $slugs = [];
        foreach ($this->forObject($object) as $field) {
            $slugs[$field->slug()]['type']                         = 'object';
            $slugs[$field->slug()]['properties'][$field->name()] = $field->schema();
        }
        $schema = [
            'type'                 => 'object',
            'description'          => 'Data plugins add, one object per plugin slug.',
            'additionalProperties' => ['type' => 'object'],
        ];
        if ($slugs !== []) {
            ksort($slugs);
            $schema['properties'] = $slugs;
        }

        return $schema;
    }
}
