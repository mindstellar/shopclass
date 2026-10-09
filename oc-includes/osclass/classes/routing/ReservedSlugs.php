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
     * $base, or $base with $separator and 1, 2... added while $taken says the slug is in use or
     * it is reserved.
     *
     * @param string   $base
     * @param callable $taken (string $slug): bool
     * @param string   $separator
     *
     * @return string
     */
    public static function unique(string $base, callable $taken, string $separator = '_'): string
    {
        $slug = $base;
        for ($n = 1; self::taken($slug) || $taken($slug); $n++) {
            $slug = $base . $separator . $n;
        }

        return $slug;
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
        // The query narrows to names starting with the prefix; taken() decides.
        $pages      = array_filter(\mindstellar\pages\PageQuery::internalNamesStartingWith('api'), [self::class, 'taken']);
        $categories = array_filter(\mindstellar\category\CategoryStore::slugsStartingWith('api'), [self::class, 'taken']);

        return ['pages' => array_values(array_unique($pages)), 'categories' => array_values(array_unique($categories))];
    }
}
