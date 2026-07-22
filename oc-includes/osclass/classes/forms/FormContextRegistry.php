<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\forms;

use InvalidArgumentException;

/**
 * Class FormContextRegistry
 *
 * Registry of form placement-context types. A submission records where a form was
 * placed as an (s_context_type, i_context_id) pair (mirroring t_resource's owner
 * pair); this registry lets core and plugins describe a context type so the
 * submissions admin can show a human label and link instead of a raw "widget #42".
 *
 * Core registers 'page' and 'widget'. A plugin that renders forms in its own
 * surface (e.g. a CRM panel) registers its own type so its submissions read
 * sensibly. Deliberately mirrors mindstellar\widgets\WidgetRegistry and
 * mindstellar\fields\FieldTypeRegistry.
 *
 * @package mindstellar\forms
 */
final class FormContextRegistry
{
    private static ?FormContextRegistry $instance = null;

    /** @var array<string,array> registered context specs, keyed by type */
    private array $contexts = array();

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
     * Register a context type.
     *
     * $spec keys:
     *   'label'   => string   Human name of the context type (e.g. 'Page'). Required.
     *   'resolve' => callable Optional callable(int $id): array returning
     *                         ['label' => string, 'url' => ?string] describing the
     *                         specific context instance for the admin. When absent,
     *                         a generic "<label> #<id>" is shown.
     *
     * @param string $type Slug, [a-z0-9_-]{1,20} (matches the stored s_context_type).
     * @param array  $spec
     *
     * @throws InvalidArgumentException on an invalid type or spec.
     */
    public function register(string $type, array $spec): void
    {
        if (!self::isValidType($type)) {
            throw new InvalidArgumentException('FormContextRegistry: invalid context type "' . $type . '"');
        }
        if (empty($spec['label']) || !is_string($spec['label'])) {
            throw new InvalidArgumentException('FormContextRegistry: context "' . $type . '" needs a string label');
        }
        if (isset($spec['resolve']) && !is_callable($spec['resolve'])) {
            throw new InvalidArgumentException('FormContextRegistry: context "' . $type . '" resolve must be callable');
        }

        $this->contexts[$type] = array(
            'type'    => $type,
            'label'   => $spec['label'],
            'resolve' => $spec['resolve'] ?? null,
        );
    }

    public function get(string $type): ?array
    {
        return $this->contexts[$type] ?? null;
    }

    /**
     * @return array<string,array>
     */
    public function all(): array
    {
        return $this->contexts;
    }

    /**
     * Describe a specific context instance for the admin: ['label' => string,
     * 'url' => ?string]. Falls back to a generic label for an unregistered type or a
     * resolver that returns nothing. Never throws — a broken plugin resolver degrades
     * to the generic label.
     *
     * @param string $type
     * @param int    $id
     *
     * @return array{label: string, url: ?string}
     */
    public function describe(string $type, int $id): array
    {
        $spec     = $this->get($type);
        $fallback = array('label' => ($spec['label'] ?? ucfirst($type)) . ' #' . $id, 'url' => null);

        if ($spec === null || $spec['resolve'] === null) {
            return $fallback;
        }
        try {
            $resolved = ($spec['resolve'])($id);
        } catch (\Throwable $e) {
            return $fallback;
        }
        if (!is_array($resolved) || empty($resolved['label']) || !is_string($resolved['label'])) {
            return $fallback;
        }

        return array(
            'label' => $resolved['label'],
            'url'   => (isset($resolved['url']) && is_string($resolved['url'])) ? $resolved['url'] : null,
        );
    }

    public static function isValidType(string $type): bool
    {
        // Namespaced slug (dots allowed, like widget/field-type ids); bounded to 20
        // to fit t_form_submission.s_context_type.
        return (bool) preg_match('/^[a-z0-9_.-]{1,20}$/', $type);
    }
}
