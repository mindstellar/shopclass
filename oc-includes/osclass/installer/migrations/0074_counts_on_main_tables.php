<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * Keep the live listing count of each country, region, city and category in an i_num_items
 * column on its own table, copy the counts over, and drop the four stats tables. Safe to re-run:
 * a column or index is added only when missing, and a stats table already gone is skipped. A
 * count lost to an interrupted run is put right by the weekly recount.
 *
 * @title Listing counts on the place and category tables
 */
return new class () implements MigrationInterface {
    /** main table => [its key, stats table, the stats key, whether the count is indexed] */
    private const TABLES = array(
        't_country'  => array('pk_c_code', 't_country_stats', 'fk_c_country_code', true),
        't_region'   => array('pk_i_id', 't_region_stats', 'fk_i_region_id', true),
        't_city'     => array('pk_i_id', 't_city_stats', 'fk_i_city_id', true),
        't_category' => array('pk_i_id', 't_category_stats', 'fk_i_category_id', false),
    );

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $p = DB_TABLE_PREFIX;
        foreach (self::TABLES as $main => [$key, $stats, $statsKey, $indexed]) {
            // Two statements: a column added alone is instant on MySQL 8 and MariaDB 10.3+, so a big
            // t_city is not rebuilt.
            if (!$this->has($conn, 'columns', $p . $main, 'column_name', 'i_num_items')) {
                $conn->execute('ALTER TABLE ' . $p . $main . ' ADD COLUMN i_num_items INT UNSIGNED NOT NULL DEFAULT 0');
            }
            if ($indexed && !$this->has($conn, 'statistics', $p . $main, 'index_name', 'idx_num_items')) {
                $conn->execute('ALTER TABLE ' . $p . $main . ' ADD INDEX idx_num_items (i_num_items)');
            }

            if (!$this->has($conn, 'tables', $p . $stats, 'table_name', $p . $stats)) {
                continue;
            }
            // A country code is text, and an old table may have another collation than the new one.
            // Only zero counts are filled, so a run stopped before the drop cannot undo newer counts.
            $match = $key === 'pk_c_code'
                ? 'CONVERT(s.' . $statsKey . ' USING utf8mb4) COLLATE utf8mb4_general_ci = m.' . $key
                : 's.' . $statsKey . ' = m.' . $key;
            $conn->execute(
                'UPDATE ' . $p . $main . ' m INNER JOIN ' . $p . $stats . ' s ON ' . $match . ' SET m.i_num_items = s.i_num_items WHERE m.i_num_items = 0'
            );
            $conn->execute('DROP TABLE ' . $p . $stats);
        }
    }

    private function has(Connection $conn, string $view, string $table, string $column, string $name): bool
    {
        return $conn->select(
            'SELECT 1 FROM information_schema.' . $view . ' WHERE table_schema = DATABASE() AND table_name = ? AND ' . $column . ' = ? LIMIT 1',
            array($table, $name)
        ) !== array();
    }
};
