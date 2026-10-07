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

namespace mindstellar\fields;

use mindstellar\database\Db;

/**
 * Custom field reads: one field's row, the fields of a category, and the values listings
 * hold, joined to their field. The legacy Field model reads its category fields from here.
 */
final class FieldQuery
{
    /**
     * @return array<string,mixed>|null the bare t_meta_fields row
     * @throws \mindstellar\database\DbException
     */
    public static function find(int $id): ?array
    {
        return Db::table(DB_TABLE_PREFIX . 't_meta_fields')->where('pk_i_id', $id)->first();
    }

    /**
     * One listing's values, each with its field's type.
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function listingValues(int $itemId): array
    {
        return Db::select(
            'SELECT m.fk_i_field_id, m.s_multi, m.s_value, f.e_type FROM ' . DB_TABLE_PREFIX . 't_item_meta m'
            . ' LEFT JOIN ' . DB_TABLE_PREFIX . 't_meta_fields f ON f.pk_i_id = m.fk_i_field_id WHERE m.fk_i_item_id = ?',
            [$itemId]
        );
    }

    /**
     * The values of several listings joined to their field, in form order.
     *
     * @param int[] $itemIds
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public static function valuesOf(array $itemIds): array
    {
        $p = DB_TABLE_PREFIX;

        return Db::select(
            'SELECT im.fk_i_item_id, mf.pk_i_id, im.s_value, im.s_multi, mf.s_name, mf.s_slug, mf.e_type, mf.s_meta'
            . ' FROM ' . $p . 't_item_meta im'
            . ' INNER JOIN ' . $p . 't_meta_fields mf ON mf.pk_i_id = im.fk_i_field_id'
            . ' WHERE im.fk_i_item_id IN (' . implode(', ', array_fill(0, count($itemIds), '?')) . ')'
            . ' ORDER BY mf.i_position ASC, mf.pk_i_id ASC',
            $itemIds
        );
    }

    /**
     * The fields that apply to a category, honouring inheritance: loose fields and the fields
     * of forms assigned to the category or any ancestor, once each, in form order. The union
     * is grouped in its own subquery to satisfy ONLY_FULL_GROUP_BY; MIN() sorts a field that is
     * both loose and in a form as loose.
     *
     * @return array<int,array<string,mixed>> t_meta_fields rows with cf_group_position
     * @throws \mindstellar\database\DbException
     */
    public static function forCategory(int $categoryId): array
    {
        $path = self::categoryPath($categoryId);
        if ($path === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($path), '?'));
        $p  = DB_TABLE_PREFIX;

        return Db::stringifyRows(Db::select(
            'SELECT mf.*, query.cf_group_position FROM ' . $p . 't_meta_fields mf JOIN ('
            . 'SELECT u.pk_i_id AS pk_i_id, MIN(u.cf_group_position) AS cf_group_position FROM ('
            . 'SELECT mfa.pk_i_id AS pk_i_id, 0 AS cf_group_position'
            . ' FROM ' . $p . 't_meta_fields mfa, ' . $p . 't_meta_categories mc'
            . ' WHERE mc.fk_i_category_id IN (' . $in . ') AND mfa.pk_i_id = mc.fk_i_field_id'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $p . 't_meta_group_fields gfx WHERE gfx.fk_i_field_id = mfa.pk_i_id)'
            . ' UNION '
            . 'SELECT mfb.pk_i_id AS pk_i_id, g.i_position AS cf_group_position FROM ' . $p . 't_meta_fields mfb'
            . ' JOIN ' . $p . 't_meta_group_fields gf ON gf.fk_i_field_id = mfb.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group g ON gf.fk_i_group_id = g.pk_i_id'
            . ' JOIN ' . $p . 't_meta_group_categories gc ON gc.fk_i_group_id = g.pk_i_id'
            . ' WHERE gc.fk_i_category_id IN (' . $in . ')'
            . ') AS u GROUP BY u.pk_i_id'
            . ') AS query ON query.pk_i_id = mf.pk_i_id'
            . ' ORDER BY query.cf_group_position ASC, mf.i_position ASC',
            array_merge($path, $path)
        ));
    }

    /**
     * The category and its ancestors, leaf first, from the cached parent map. A parent
     * cycle stops at the first repeat.
     *
     * @return int[]
     */
    public static function categoryPath(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        $parents = \mindstellar\cache\CacheGroup::remember('category', 'parents', static function () {
            try {
                $rows = Db::select('SELECT pk_i_id, fk_i_parent_id FROM ' . DB_TABLE_PREFIX . 't_category');
            } catch (\mindstellar\database\DbException $e) {
                return null;
            }

            return array_map('intval', array_column($rows, 'fk_i_parent_id', 'pk_i_id'));
        }) ?? [];
        $path    = [];
        $current = $categoryId;
        while ($current > 0 && count($path) < 100 && !in_array($current, $path, true)) {
            $path[]  = $current;
            $current = $parents[$current] ?? 0;
        }

        return $path;
    }

    /**
     * Up to ten distinct values of a field on live listings that match a LIKE pattern.
     *
     * @return array<int,array<string,mixed>> rows with value
     * @throws \mindstellar\database\DbException
     */
    public static function suggest(int $fieldId, string $like): array
    {
        return Db::select(
            'SELECT DISTINCT m.s_value AS value FROM ' . DB_TABLE_PREFIX . 't_item_meta m'
            . ' JOIN ' . DB_TABLE_PREFIX . 't_item i ON i.pk_i_id = m.fk_i_item_id'
            . ' WHERE m.fk_i_field_id = ? AND m.s_value LIKE ?'
            . ' AND i.b_active = 1 AND i.b_enabled = 1 AND i.b_spam = 0'
            . ' ORDER BY m.s_value LIMIT 10',
            array($fieldId, $like)
        );
    }
}
