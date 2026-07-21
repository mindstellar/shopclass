<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\widgets;

use InvalidArgumentException;

/**
 * Class WidgetRegistry
 *
 * Registry of widget types. A widget type is a reusable, functional widget
 * (rendered by a callable) that plugins and themes register once and site
 * owners then place from the admin appearance screen. Legacy stored-content
 * widgets (rows with no s_type) never touch this registry.
 *
 * Types are keyed by a namespaced slug (e.g. 'core.custom_code',
 * 'sample_widgets.recent_listings'), validated as [a-z0-9_.-]{1,60}.
 *
 * @package mindstellar\widgets
 */
final class WidgetRegistry
{
    private static ?WidgetRegistry $instance = null;

    /** @var array<string,array> registered type specs, keyed by type id */
    private array $types = [];

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
     * Register a widget type.
     *
     * $spec keys:
     *   'label'       => string    Shown in the admin picker. Required.
     *   'description' => string    Optional one-line description.
     *   'render'      => callable  callable(array $config, array $widgetRow): void|string.
     *                              Required. May echo directly or return a string.
     *   'fields'      => array     Optional declarative config schema. Each entry:
     *                              ['name'=>string, 'label'=>string,
     *                               'type'=>'text'|'number'|'select'|'checkbox'|'textarea',
     *                               'default'=>mixed, 'options'=>array (select only)].
     *   'form'        => callable  Optional callable(array $config): void that renders
     *                              a custom admin form, overriding 'fields'.
     *   'capability'  => string    'admin' (default) or 'super_admin'. A 'super_admin'
     *                              type may only be authored by a non-moderator admin.
     *
     * @param string $id   Namespaced slug, [a-z0-9_.-]{1,60}.
     * @param array  $spec Type specification (see above).
     *
     * @throws InvalidArgumentException on an invalid id or spec.
     */
    public function register(string $id, array $spec): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException(
                'WidgetRegistry: invalid widget type id "' . $id . '" (expected [a-z0-9_.-]{1,60})'
            );
        }

        if (empty($spec['label']) || !is_string($spec['label'])) {
            throw new InvalidArgumentException('WidgetRegistry: widget type "' . $id . '" needs a string label');
        }

        if (!isset($spec['render']) || !is_callable($spec['render'])) {
            throw new InvalidArgumentException('WidgetRegistry: widget type "' . $id . '" needs a callable render');
        }

        if (isset($spec['form']) && !is_callable($spec['form'])) {
            throw new InvalidArgumentException('WidgetRegistry: widget type "' . $id . '" form must be callable');
        }

        $fields = [];
        if (isset($spec['fields'])) {
            if (!is_array($spec['fields'])) {
                throw new InvalidArgumentException('WidgetRegistry: widget type "' . $id . '" fields must be an array');
            }
            $fields = $spec['fields'];
        }

        $capability = $spec['capability'] ?? 'admin';
        if ($capability !== 'admin' && $capability !== 'super_admin') {
            $capability = 'admin';
        }

        $this->types[$id] = [
            'id'          => $id,
            'label'       => $spec['label'],
            'description' => isset($spec['description']) && is_string($spec['description']) ? $spec['description'] : '',
            'render'      => $spec['render'],
            'fields'      => $fields,
            'form'        => $spec['form'] ?? null,
            'capability'  => $capability,
        ];
    }

    /**
     * The spec for a registered type, or null when the id is not registered
     * (e.g. its plugin is deactivated).
     */
    public function get(string $id): ?array
    {
        return $this->types[$id] ?? null;
    }

    /**
     * All registered type specs, keyed by id.
     *
     * @return array<string,array>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Whether $id is a well-formed widget type id.
     */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9_.-]{1,60}$/', $id);
    }
}
