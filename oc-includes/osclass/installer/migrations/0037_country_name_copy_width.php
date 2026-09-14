<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * t_user.s_country and t_item_location.s_country hold a copy of t_country.s_name,
 * which is VARCHAR(80) -- but both copies were declared VARCHAR(40), so a country
 * name the catalog accepts did not fit the column it is copied into. On a relaxed
 * connection it was stored cut in half; on a strict one it refused the whole write.
 *
 * The catalog is downloaded, not shipped, so the country list an install carries is
 * not the limit. Widening the copies to their source is what lets validation cap
 * these fields at a width every legitimate choice fits inside.
 *
 * Idempotent: the ALTER is skipped once the column is already at least that wide.
 */
return new class () implements MigrationInterface {
    /** Tables carrying an s_country copy of t_country.s_name. */
    private const TABLES = array('t_user', 't_item_location');

    /**
     * Widen s_country to VARCHAR(80) on t_user and t_item_location wherever it is
     * still narrower than t_country.s_name.
     *
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        foreach (self::TABLES as $table) {
            $name = DB_TABLE_PREFIX . $table;
            if ($this->widthOf($conn, $name, 's_country') >= 80) {
                continue;
            }

            $conn->execute('ALTER TABLE ' . $name . ' MODIFY s_country VARCHAR(80) NULL');
        }
    }

    /**
     * Declared character length of $column, or 0 when the column is absent.
     *
     * @param Connection $conn
     * @param string     $table
     * @param string     $column
     *
     * @return int
     * @throws \mindstellar\database\DbException
     */
    private function widthOf(Connection $conn, string $table, string $column): int
    {
        return (int) $conn->scalar(
            'SELECT COALESCE(MAX(character_maximum_length), 0) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND COLUMN_NAME = ?',
            array($table, $column)
        );
    }
};
