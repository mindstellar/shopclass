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

namespace mindstellar\category;

/**
 * How many levels of categories the active theme shows, from
 * osc_add_theme_support('category-depth', N). No declaration means no limit.
 */
final class CategoryDepth
{
    /** Preference that keeps the declared value for requests that never load the theme. */
    public const PREFERENCE = 'category_depth';

    /**
     * The limit, or null for none. The API never loads the theme, so it reads the stored copy.
     */
    public static function limit(): ?int
    {
        if (\osc_is_api_request()) {
            $stored = \osc_get_preference(self::PREFERENCE);

            return is_string($stored) && ctype_digit($stored) && (int)$stored >= 1 ? (int)$stored : null;
        }

        return self::valid(\osc_theme_supports('category-depth'));
    }

    /**
     * Whether moving the subtree at $id under $newParent (null = top level) keeps it within the
     * limit. A tree that is already too deep may move sideways or up, never deeper.
     */
    public static function allows(CategoryTree $tree, int $id, ?int $newParent): bool
    {
        $limit = self::limit();
        if ($limit === null) {
            return true;
        }
        $height  = $tree->height($id);
        $deepest = ($newParent === null ? 0 : $tree->depth($newParent)) + $height;

        return $deepest <= $limit || $deepest <= $tree->depth($id) + $height - 1;
    }

    /**
     * Whether a new category fits directly under $parent (null = top level).
     */
    public static function allowsChild(CategoryTree $tree, ?int $parent): bool
    {
        $limit = self::limit();

        return $limit === null || ($parent === null ? 0 : $tree->depth($parent)) + 1 <= $limit;
    }

    /**
     * Admin requests keep the stored copy in step with the theme, writing only on a change.
     */
    public static function sync(): void
    {
        if (\osc_is_api_request()) {
            return;
        }
        $declared = self::valid(\osc_theme_supports('category-depth'));
        $value    = $declared === null ? '' : (string)$declared;
        if ((string)\osc_get_preference(self::PREFERENCE) !== $value) {
            \osc_set_preference(self::PREFERENCE, $value);
        }
    }

    /**
     * @param mixed $value
     */
    private static function valid($value): ?int
    {
        return is_int($value) && $value >= 1 ? $value : null;
    }
}
