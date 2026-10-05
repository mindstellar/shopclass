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

namespace mindstellar\routing;

/**
 * URL prefixes core owns ahead of every admin-editable route, so no static page or category
 * may take one as its slug: the REST API answers `/api` and everything under it.
 */
final class ReservedSlugs
{
    public const PREFIXES = ['api'];

    private function __construct()
    {
    }

    /**
     * Whether $slug is a reserved prefix, or starts with one and a slash.
     */
    public static function taken(string $slug): bool
    {
        $slug = strtolower(trim($slug, " \t\n\r\0\x0B/"));
        foreach (self::PREFIXES as $prefix) {
            if ($slug === $prefix || str_starts_with($slug, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message a form shows for a reserved slug.
     */
    public static function message(): string
    {
        return __('A slug of "api", or one starting with "api/", is reserved for the site\'s API. Choose another.');
    }

    /**
     * Static pages and categories whose slug is reserved, for System info and doctor: they
     * were saved before the prefix was reserved and their pages are not reachable.
     *
     * @return array{pages: string[], categories: string[]}
     */
    public static function conflicts(): array
    {
        $pages = [];
        // LIKE narrows to rows starting with a prefix; taken() decides.
        foreach (osc_db_table(DB_TABLE_PREFIX . 't_pages')->select('s_internal_name')->like('s_internal_name', 'api', 'after')->get() as $row) {
            $name = (string) $row['s_internal_name'];
            if (self::taken($name)) {
                $pages[] = $name;
            }
        }
        $categories = [];
        foreach (osc_db_table(DB_TABLE_PREFIX . 't_category_description')->select('s_slug')->like('s_slug', 'api', 'after')->get() as $row) {
            $slug = (string) $row['s_slug'];
            if (self::taken($slug)) {
                $categories[] = $slug;
            }
        }

        return ['pages' => array_values(array_unique($pages)), 'categories' => array_values(array_unique($categories))];
    }
}
