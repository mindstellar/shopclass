<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\fields;

use InvalidArgumentException;

/**
 * Class FieldTypeRegistry
 *
 * Registry of custom-field types. A field type is a named input (text, dropdown,
 * a plugin's "email" or "price range", …) that core and plugins register once and
 * the field builder then offers when creating a field.
 *
 * It replaces switching on the t_meta_fields.e_type ENUM as the sole source of
 * truth. e_type is kept as the stored *storage primitive* (one of the original
 * ENUM values) so old data and third-party themes keep working unchanged; a type
 * declares which primitive it stores as via 'storage'. A type whose id is not one
 * of the built-in primitives (e.g. a plugin 'email') persists its real identity in
 * the field's s_meta ('type') while e_type holds the primitive — so no schema/ENUM
 * change is needed to add a type.
 *
 * Ids are [A-Z0-9_]+ for the built-ins (matching the historical ENUM spelling) or
 * namespaced slugs [a-z0-9_.-]{1,60} for plugin types, so the two read the same to
 * an author. Deliberately mirrors mindstellar\pages\PageTemplateRegistry and
 * mindstellar\widgets\WidgetRegistry.
 *
 * @package mindstellar\fields
 */
final class FieldTypeRegistry
{
    /** The storage primitives — the historical e_type ENUM values a type may map to. */
    public const STORAGE_PRIMITIVES = array(
        'TEXT',
        'NUMBER',
        'TEXTAREA',
        'DROPDOWN',
        'RADIO',
        'CHECKBOX',
        'URL',
        'DATE',
        'DATEINTERVAL',
    );

    private static ?FieldTypeRegistry $instance = null;

    /** @var array<string,array> registered type specs, keyed by id */
    private array $types = array();

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a field type.
     *
     * $spec keys:
     *   'label'      => string   Shown in the builder's type picker. Required.
     *   'storage'    => string   Which STORAGE_PRIMITIVE the value is stored as.
     *                            Defaults to the id when the id is itself a
     *                            primitive, otherwise 'TEXT'.
     *   'group'      => string   Optional palette grouping label (e.g. 'Basic',
     *                            'Choice', 'Advanced').
     *   'icon'       => string   Optional bootstrap-icon name for the palette.
     *   'has_options'=> bool     Whether the type uses the comma-separated options
     *                            list (DROPDOWN/RADIO/MULTISELECT). Default false.
     *   'config'     => string[] Optional s_meta config keys the builder exposes for
     *                            this type (e.g. ['placeholder','help_text','min']).
     *   'render'     => callable Optional callable(array $field): void that emits
     *                            the input. When absent the storage primitive's
     *                            native rendering is used.
     *   'sanitize'   => callable Optional callable(mixed $value): mixed.
     *   'validate'   => callable Optional callable(mixed $value, array $field): ?string
     *                            returning an error message or null when valid.
     *
     * @param string $id   Built-in primitive (UPPER_CASE) or namespaced slug.
     * @param array  $spec Type specification (see above).
     *
     * @throws InvalidArgumentException on an invalid id or spec.
     */
    public function register(string $id, array $spec): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException(
                'FieldTypeRegistry: invalid type id "' . $id . '"'
            );
        }

        if (empty($spec['label']) || !is_string($spec['label'])) {
            throw new InvalidArgumentException('FieldTypeRegistry: type "' . $id . '" needs a string label');
        }

        $storage = $spec['storage'] ?? (in_array($id, self::STORAGE_PRIMITIVES, true) ? $id : 'TEXT');
        if (!in_array($storage, self::STORAGE_PRIMITIVES, true)) {
            throw new InvalidArgumentException(
                'FieldTypeRegistry: type "' . $id . '" storage "' . $storage . '" is not a valid primitive'
            );
        }

        foreach (array('render', 'sanitize', 'validate') as $cb) {
            if (isset($spec[$cb]) && !is_callable($spec[$cb])) {
                throw new InvalidArgumentException(
                    'FieldTypeRegistry: type "' . $id . '" ' . $cb . ' must be callable'
                );
            }
        }

        $this->types[$id] = array(
            'id'          => $id,
            'label'       => $spec['label'],
            'storage'     => $storage,
            'group'       => isset($spec['group']) && is_string($spec['group']) ? $spec['group'] : 'General',
            'icon'        => isset($spec['icon']) && is_string($spec['icon']) ? $spec['icon'] : '',
            'has_options' => !empty($spec['has_options']),
            'config'      => isset($spec['config']) && is_array($spec['config']) ? array_values($spec['config']) : array(),
            'render'      => $spec['render'] ?? null,
            'sanitize'    => $spec['sanitize'] ?? null,
            'validate'    => $spec['validate'] ?? null,
        );
    }

    /**
     * The spec for a registered type, or null when the id is not registered (e.g.
     * its plugin is deactivated).
     */
    public function get(string $id): ?array
    {
        return $this->types[$id] ?? null;
    }

    /**
     * All registered type specs, keyed by id, in registration order.
     *
     * @return array<string,array>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The storage primitive a type stores as, or the id itself when unregistered
     * but already a primitive, or 'TEXT' as a last resort.
     */
    public function storageOf(string $id): string
    {
        $spec = $this->get($id);
        if ($spec !== null) {
            return $spec['storage'];
        }

        return in_array($id, self::STORAGE_PRIMITIVES, true) ? $id : 'TEXT';
    }

    /**
     * Whether $id is a well-formed field type id (built-in primitive spelling or a
     * namespaced plugin slug).
     */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[A-Z0-9_]{1,40}$/', $id)
            || (bool) preg_match('/^[a-z0-9_.-]{1,60}$/', $id);
    }
}
