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
 * Small t_category reads and writes for the category services. The
 * legacy Category and CategoryStats models keep their own methods.
 */
final class CategoryStore extends Model
{
    protected const TABLE = 't_category';

    /** @var array<int,?int>|null */
    private static ?array $parentMap = null;

    /**
     * Every category's parent in one query (id => parent id, null for the top level),
     * in sibling order. Kept for the rest of the request; any write here clears it.
     *
     * @return array<int,?int>
     * @throws \mindstellar\database\DbException
     */
    public static function parentMap(): array
    {
        if (self::$parentMap === null) {
            $map = array();
            $rows = self::table()->select('pk_i_id', 'fk_i_parent_id')->orderBy('i_position')->orderBy('pk_i_id')->get();
            foreach ($rows as $row) {
                $map[(int) $row['pk_i_id']] = $row['fk_i_parent_id'] === null ? null : (int) $row['fk_i_parent_id'];
            }
            self::$parentMap = $map;
        }

        return self::$parentMap;
    }

    /**
     * The tree read fresh from the database. Legacy model writes do not clear the remembered
     * map, so every caller that needs the current tree uses this.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function tree(): CategoryTree
    {
        self::forget();

        return new CategoryTree(self::parentMap());
    }

    /**
     * Drop the remembered tree.
     */
    public static function forget(): void
    {
        self::$parentMap = null;
    }

    /**
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function setEnabled(array $ids, bool $on): void
    {
        self::forget();
        if ($ids !== array()) {
            self::table()->whereIn('pk_i_id', array_map('intval', $ids))->update(array('b_enabled' => $on ? 1 : 0));
        }
    }

    /**
     * Sets the category rows only; listings are not touched.
     *
     * @param int[] $ids
     *
     * @throws \mindstellar\database\DbException
     */
    public static function setExpirationDays(array $ids, int $days): void
    {
        self::forget();
        if ($ids !== array()) {
            self::table()->whereIn('pk_i_id', array_map('intval', $ids))->update(array('i_expiration_days' => $days));
        }
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public static function setParent(int $id, ?int $parentId): void
    {
        self::forget();
        self::table()->where('pk_i_id', $id)->update(array('fk_i_parent_id' => $parentId));
    }

    /**
     * Number the given categories 0, 1, 2... in i_position, in one statement.
     *
     * @param int[] $orderedIds
     *
     * @throws \mindstellar\database\DbException
     */
    public static function reorder(array $orderedIds): void
    {
        self::forget();
        $ids = array_values(array_map('intval', $orderedIds));
        if ($ids === array()) {
            return;
        }

        $case = '';
        $bindings = array();
        foreach ($ids as $position => $id) {
            $case .= ' WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $position;
        }
        $bindings = array_merge($bindings, $ids);

        Db::execute(
            'UPDATE ' . self::tableName() . ' SET i_position = CASE pk_i_id' . $case . ' END WHERE pk_i_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')',
            $bindings
        );
    }

    /**
     * Ids directly under $parentId (null = top level), in i_position then id order.
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    public static function siblings(?int $parentId): array
    {
        return array_keys(array_filter(self::parentMap(), static fn (?int $p): bool => $p === $parentId));
    }

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
     * The enabled flag and listing count of a category, or null when it does not exist.
     *
     * @return array{b_enabled:mixed,i_num_items:mixed}|null
     *
     * @throws \mindstellar\database\DbException
     */
    public static function flagsAndCount(int $id): ?array
    {
        return self::table()->select('b_enabled', 'i_num_items')->where('pk_i_id', $id)->first();
    }

    /**
     * Whether a listing in this category asks for a price. An unknown category does.
     *
     * @throws \mindstellar\database\DbException
     */
    public static function takesPrice(int $id): bool
    {
        $row = self::table()->select('b_price_enabled')->where('pk_i_id', $id)->first();

        return $row === null || (int) $row['b_price_enabled'] === 1;
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
