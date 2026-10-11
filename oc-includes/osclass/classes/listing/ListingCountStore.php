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

namespace mindstellar\listing;

use mindstellar\database\Db;

/**
 * The live listing count in the i_num_items column of t_country, t_region, t_city or t_category.
 * Ids are bound as text: PHP turns a key like '0' into int 0, and MySQL compares a country code
 * with 0 as a number, which every alphabetic code would match.
 */
final class ListingCountStore
{
    private string $table;

    /**
     * @param string $table the table without its prefix
     * @param string $key   its primary key
     */
    private function __construct(string $table, private string $key)
    {
        $this->table = DB_TABLE_PREFIX . $table;
    }

    public static function countries(): self
    {
        return new self('t_country', 'pk_c_code');
    }

    public static function regions(): self
    {
        return new self('t_region', 'pk_i_id');
    }

    public static function cities(): self
    {
        return new self('t_city', 'pk_i_id');
    }

    public static function categories(): self
    {
        return new self('t_category', 'pk_i_id');
    }

    public function table(): string
    {
        return $this->table;
    }

    /**
     * Add $delta to the count of each row, never below zero.
     *
     * @param array<int,int|string> $ids
     *
     * @return int rows changed
     * @throws \mindstellar\database\DbException
     */
    public function add(array $ids, int $delta): int
    {
        if ($ids === array()) {
            return 0;
        }
        $in   = ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
        $ids  = array_map('strval', array_values($ids));
        $step = abs($delta);

        return $delta >= 0
            ? Db::execute('UPDATE ' . $this->table . ' SET i_num_items = i_num_items + ? WHERE ' . $this->key . $in, array_merge(array($step), $ids))
            : Db::execute('UPDATE ' . $this->table . ' SET i_num_items = i_num_items - ? WHERE i_num_items >= ? AND ' . $this->key . $in, array_merge(array($step, $step), $ids));
    }

    /**
     * Set the counts of many rows in one statement.
     *
     * @param array<int|string,int|string> $counts id => count
     *
     * @return bool false when there is nothing to write
     * @throws \mindstellar\database\DbException
     */
    public function set(array $counts): bool
    {
        if ($counts === array()) {
            return false;
        }
        $params = array();
        foreach ($counts as $id => $count) {
            $params[] = (string) $id;
            $params[] = (int) $count;
        }
        $ids = array_map('strval', array_keys($counts));
        Db::execute(
            'UPDATE ' . $this->table . ' SET i_num_items = CASE ' . $this->key . ' '
            . implode(' ', array_fill(0, count($counts), 'WHEN ? THEN ?')) . ' ELSE i_num_items END'
            . ' WHERE ' . $this->key . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            array_merge($params, $ids)
        );

        return true;
    }

    /**
     * One row's count under $as, the key name the old stats table used, or null when there is no row.
     *
     * @return array<string,string>|null
     * @throws \mindstellar\database\DbException
     */
    public function row(int|string $id, string $as): ?array
    {
        $row = Db::table($this->table)->select($this->key, 'i_num_items')->where($this->key, (string) $id)->first();

        return $row === null ? null : array($as => (string) $row[$this->key], 'i_num_items' => (string) $row['i_num_items']);
    }

    /**
     * Every row's count.
     *
     * @return array<string,string> id => count
     * @throws \mindstellar\database\DbException
     */
    public function all(): array
    {
        $out = array();
        foreach (Db::table($this->table)->select($this->key, 'i_num_items')->get() as $row) {
            $out[(string) $row[$this->key]] = (string) $row['i_num_items'];
        }

        return $out;
    }
}
