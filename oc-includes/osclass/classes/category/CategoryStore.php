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

use mindstellar\base\Model;
use mindstellar\database\Db;

/**
 * Small t_category and t_category_stats reads and writes for the category services. The
 * legacy Category and CategoryStats models keep their own methods.
 */
final class CategoryStore extends Model
{
    protected const TABLE = 't_category';

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function exists(int $id): bool
    {
        return self::table()->where('pk_i_id', $id)->first() !== null;
    }

    /**
     * How many of these ids are categories.
     *
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function countIds(array $ids): int
    {
        return self::table()->whereIn('pk_i_id', $ids)->count();
    }

    /**
     * How many categories sit directly under $parentId; null counts the top level.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function childCount(?int $parentId): int
    {
        $query = self::table();
        $query = $parentId === null ? $query->whereNull('fk_i_parent_id') : $query->where('fk_i_parent_id', $parentId);

        return $query->count();
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function isEnabled(int $id): bool
    {
        $row = self::table()->select('b_enabled')->where('pk_i_id', $id)->first();

        return !empty($row['b_enabled']);
    }

    /**
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function disable(array $ids): void
    {
        self::table()->whereIn('pk_i_id', $ids)->update(array('b_enabled' => 0));
    }

    /**
     * Write listing counts per category.
     *
     * @param array<int,int> $totals category id => count
     *
     * @throws \mindstellar\database\DbException
     */
    public static function writeCounts(array $totals): void
    {
        if ($totals === array()) {
            return;
        }
        $params = array();
        foreach ($totals as $categoryId => $total) {
            $params[] = (int) $categoryId;
            $params[] = (int) $total;
        }
        Db::execute(
            'REPLACE INTO ' . DB_TABLE_PREFIX . 't_category_stats (fk_i_category_id, i_num_items) VALUES '
            . implode(', ', array_fill(0, count($totals), '(?, ?)')),
            $params
        );
    }

    /**
     * The category an old slug last belonged to, or null.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function idForOldSlug(string $slug): ?int
    {
        $id = Db::scalar(
            'SELECT fk_i_category_id FROM ' . DB_TABLE_PREFIX . 't_category_slug_history WHERE s_slug = ? ORDER BY dt_date DESC LIMIT 1',
            array($slug)
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Category slugs, in any locale, that start with $prefix.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public static function slugsStartingWith(string $prefix): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['s_slug'],
            Db::table(DB_TABLE_PREFIX . 't_category_description')->select('s_slug')->like('s_slug', $prefix, 'after')->get()
        );
    }
}
