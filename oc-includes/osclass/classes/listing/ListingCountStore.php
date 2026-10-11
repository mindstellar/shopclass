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
     * @param string      $table the table without its prefix
     * @param string      $key   its primary key
     * @param string|null $place the t_item_location column holding this key, null for categories
     */
    private function __construct(string $table, private string $key, private ?string $place = null)
    {
        $this->table = DB_TABLE_PREFIX . $table;
    }

    public static function countries(): self
    {
        return new self('t_country', 'pk_c_code', 'fk_c_country_code');
    }

    public static function regions(): self
    {
        return new self('t_region', 'pk_i_id', 'fk_i_region_id');
    }

    public static function cities(): self
    {
        return new self('t_city', 'pk_i_id', 'fk_i_city_id');
    }

    public static function categories(): self
    {
        return new self('t_category', 'pk_i_id');
    }

    /**
     * Count the live listings of each place from the listings themselves, for a recount.
     *
     * @param array<int,int|string> $ids
     *
     * @return array<string,string> id => count, '0' for a place with none
     * @throws \mindstellar\database\DbException
     */
    public function recount(array $ids): array
    {
        if ($this->place === null) {
            throw new \LogicException('Category counts are recounted by CategoryStore.');
        }
        if ($ids === array()) {
            return array();
        }
        $ids  = array_map('strval', array_values($ids));
        $p    = DB_TABLE_PREFIX;
        $live = implode(' AND ', ListingStatus::liveConditions('i.'));
        $rows = Db::select(
            'SELECT l.' . $this->place . ' AS k, COUNT(*) AS n FROM ' . $p . 't_item_location l'
            . ' JOIN ' . $p . 't_item i ON i.pk_i_id = l.fk_i_item_id JOIN ' . $p . 't_category c ON c.pk_i_id = i.fk_i_category_id'
            . ' WHERE ' . $live . ' AND c.b_enabled = 1 AND l.' . $this->place . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' GROUP BY l.' . $this->place,
            $ids
        );
        // Keyed by the ids as given: the database matches a country code in any case.
        $given = array_combine(array_map('strtolower', $ids), $ids);
        $out   = array_fill_keys($ids, '0');
        foreach ($rows as $row) {
            $out[$given[strtolower((string) $row['k'])] ?? (string) $row['k']] = (string) $row['n'];
        }

        return $out;
    }

    /**
     * The places whose count compares with 0 by $zero, as rows of $columns, ordered by $order.
     * $zero and $order fall back to '>' and $defaultOrder when they are not a plain operator and
     * '<column> ASC|DESC'; $where adds column = value filters, which are bound.
     *
     * @param array<string,int|string> $where
     *
     * @return array<int,array<string,string|null>>
     * @throws \mindstellar\database\DbException
     */
    public function listing(string $columns, string $zero, string $order, string $defaultOrder, array $where = array()): array
    {
        if (!in_array($zero, array('>', '>=', '<', '<=', '=', '<>', '!='), true)) {
            $zero = '>';
        }
        $order = self::order($order, $defaultOrder);
        $sql = 'SELECT ' . $columns . ' FROM ' . $this->table . ' WHERE i_num_items ' . $zero . ' 0';
        foreach (array_keys($where) as $column) {
            $sql .= ' AND ' . $column . ' = ?';
        }

        return Db::stringifyRows(Db::select($sql . ' ORDER BY ' . $order, array_values($where)));
    }

    /**
     * $order when it is '<column> ASC|DESC', else $default.
     */
    public static function order(string $order, string $default): string
    {
        return preg_match('/^[A-Za-z0-9_.]+ (ASC|DESC)$/i', $order) ? $order : $default;
    }

    /**
     * Put the count of every row where $column is $value back to zero.
     *
     * @throws \mindstellar\database\DbException
     */
    public function clear(string $column, int|string $value): void
    {
        Db::table($this->table)->where($column, $value)->update(array('i_num_items' => 0));
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
     * The counts of these rows.
     *
     * @param array<int,int|string> $ids
     *
     * @return array<string,string> id => count, for the ids that exist
     * @throws \mindstellar\database\DbException
     */
    public function many(array $ids): array
    {
        if ($ids === array()) {
            return array();
        }
        $out = array();
        foreach (Db::table($this->table)->select($this->key, 'i_num_items')->whereIn($this->key, array_map('strval', array_values($ids)))->get() as $row) {
            $out[(string) $row[$this->key]] = (string) $row['i_num_items'];
        }

        return $out;
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
