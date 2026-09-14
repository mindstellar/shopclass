<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form\store;

/**
 * Turns the normalised 'store' key of a page spec into the store that implements it.
 *
 * SettingsPageRegistry has already refused anything that is not one of these, so this
 * has no error case: a page that declared nothing is a preference page, which is what
 * keeps every page registered today working with no edit.
 *
 * @package mindstellar\admin\form\store
 */
final class StoreFactory
{
    /**
     * The store a normalised page spec asks for: a TableStore when it declared one, a
     * PreferenceStore otherwise.
     *
     * @param array<string,mixed> $page normalised page spec
     *
     * @return Store
     */
    public static function forPage(array $page): Store
    {
        if (self::isTable($page)) {
            return new TableStore((string)$page['store']['table'], (string)$page['store']['pk']);
        }

        return new PreferenceStore((string)($page['section'] ?? ''));
    }

    /**
     * Whether the page writes one row of a table rather than preferences.
     *
     * A table-backed page cannot be driven by a controller that does not know which row
     * it is editing, so the decision has to be askable before a save is attempted.
     *
     * @param array<string,mixed> $page normalised page spec
     *
     * @return bool
     */
    public static function isTable(array $page): bool
    {
        return (($page['store']['type'] ?? 'preference')) === 'table';
    }
}
