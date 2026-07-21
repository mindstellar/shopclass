<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\pages;

use InvalidArgumentException;

/**
 * Class PageTemplateRegistry
 *
 * Registry of static-page templates. A page template is a named layout a plugin
 * or theme registers once and site owners then pick per page from the page
 * editor. It replaces the file-name-in-meta convention (a theme file matching
 * `template-*` stored verbatim in a page's s_meta) with a validated, id-keyed
 * source shared by themes and plugins alike.
 *
 * Ids are namespaced slugs (e.g. 'core.default', 'sample_widgets.landing'),
 * validated as [a-z0-9_.-]{1,60} — identical to the widget type registry, so the
 * two systems read the same to an author.
 *
 * Deliberately mirrors mindstellar\widgets\WidgetRegistry: the front controller
 * resolves a page's stored template id here first and falls back to the legacy
 * file-path branches when the id is not registered, so nothing that works today
 * breaks.
 *
 * @package mindstellar\pages
 */
final class PageTemplateRegistry
{
    private static ?PageTemplateRegistry $instance = null;

    /** @var array<string,array> registered template specs, keyed by id */
    private array $templates = [];

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
     * Register a page template.
     *
     * $spec keys:
     *   'label'       => string          Shown in the editor picker. Required.
     *   'description' => string          Optional one-line description.
     *   'render'      => callable|string Required. A callable(array $page): void
     *                                    that emits the page, or a file path
     *                                    resolved against the active theme dir
     *                                    then the plugins dir and required in the
     *                                    page scope.
     *   'capability'  => string          'admin' (default) or 'super_admin'. A
     *                                    'super_admin' template is hidden from the
     *                                    picker for a moderator.
     *   'builder'     => bool            Optional. Marks the template as a widget
     *                                    canvas (consumed in a later phase).
     *   'locations'   => string[]        Optional. Widget-area suffixes the builder
     *                                    exposes (consumed in a later phase).
     *
     * @param string $id   Namespaced slug, [a-z0-9_.-]{1,60}.
     * @param array  $spec Template specification (see above).
     *
     * @throws InvalidArgumentException on an invalid id or spec.
     */
    public function register(string $id, array $spec): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException(
                'PageTemplateRegistry: invalid template id "' . $id . '" (expected [a-z0-9_.-]{1,60})'
            );
        }

        if (empty($spec['label']) || !is_string($spec['label'])) {
            throw new InvalidArgumentException('PageTemplateRegistry: template "' . $id . '" needs a string label');
        }

        $render = $spec['render'] ?? null;
        if (!is_callable($render) && !(is_string($render) && $render !== '')) {
            throw new InvalidArgumentException(
                'PageTemplateRegistry: template "' . $id . '" needs a callable or non-empty file-path render'
            );
        }

        $capability = $spec['capability'] ?? 'admin';
        if ($capability !== 'admin' && $capability !== 'super_admin') {
            $capability = 'admin';
        }

        $locations = [];
        if (isset($spec['locations'])) {
            if (!is_array($spec['locations'])) {
                throw new InvalidArgumentException(
                    'PageTemplateRegistry: template "' . $id . '" locations must be an array'
                );
            }
            $locations = array_values($spec['locations']);
        }

        $this->templates[$id] = [
            'id'          => $id,
            'label'       => $spec['label'],
            'description' => isset($spec['description']) && is_string($spec['description']) ? $spec['description'] : '',
            'render'      => $render,
            'capability'  => $capability,
            'builder'     => !empty($spec['builder']),
            'locations'   => $locations,
        ];
    }

    /**
     * The spec for a registered template, or null when the id is not registered
     * (e.g. its plugin is deactivated).
     */
    public function get(string $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    /**
     * All registered template specs, keyed by id.
     *
     * @return array<string,array>
     */
    public function all(): array
    {
        return $this->templates;
    }

    /**
     * Whether $id is a well-formed page template id.
     */
    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9_.-]{1,60}$/', $id);
    }
}
