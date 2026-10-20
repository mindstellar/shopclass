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
 * Move category and location slug history into one t_slug_history table, then drop the two
 * old tables. An old table is dropped only when all its rows arrived. Safe to re-run: a
 * moved row is kept, and a missing old table is skipped.
 *
 * @title One slug history table
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $p   = DB_TABLE_PREFIX;
        $new = $p . 't_slug_history';
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $new . " (e_type ENUM('CATEGORY', 'REGION', 'CITY') NOT NULL,"
            . " fk_i_id INT UNSIGNED NOT NULL, fk_c_locale_code CHAR(5) NOT NULL DEFAULT '', s_slug VARCHAR(191) NOT NULL,"
            . ' dt_date DATETIME NOT NULL, PRIMARY KEY (e_type, s_slug, fk_c_locale_code), INDEX idx_owner (e_type, fk_i_id))'
            . " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );

        $moves = array(
            't_category_slug_history' => array(
                "SELECT 'CATEGORY', fk_i_category_id, fk_c_locale_code, s_slug, dt_date FROM %s",
                "n.e_type = 'CATEGORY' AND n.fk_i_id = o.fk_i_category_id AND n.s_slug = %1\$s AND n.fk_c_locale_code = %2\$s",
            ),
            't_location_slug_history' => array(
                "SELECT e_type, fk_i_id, '', s_slug, dt_date FROM %s",
                "n.e_type = %3\$s AND n.fk_i_id = o.fk_i_id AND n.s_slug = %1\$s AND n.fk_c_locale_code = ''",
            ),
        );
        foreach ($moves as $table => [$select, $match]) {
            $old = $p . $table;
            if (!$this->exists($conn, $old)) {
                continue;
            }
            $conn->execute(
                'INSERT IGNORE INTO ' . $new . ' (e_type, fk_i_id, fk_c_locale_code, s_slug, dt_date) ' . sprintf($select, $old)
            );
            // DDL cannot be rolled back, so the old table goes only when every row is in the new one.
            // An old table made with another collation still compares, in the new table's.
            $text    = static fn (string $column): string => 'CONVERT(o.' . $column . " USING utf8mb4) COLLATE utf8mb4_general_ci";
            $missing = (int) ($conn->select(
                'SELECT COUNT(*) AS n FROM ' . $old . ' o WHERE NOT EXISTS (SELECT 1 FROM ' . $new . ' n WHERE '
                . sprintf($match, $text('s_slug'), $text('fk_c_locale_code'), $text('e_type')) . ')'
            )[0]['n'] ?? 0);
            if ($missing > 0) {
                // The likely cause is a case-sensitive collation on the old table holding two slugs that differ only in case.
                throw new RuntimeException($missing . ' rows of ' . $table . ' did not reach t_slug_history, so it is kept.'
                    . ' Delete old slugs there that differ only in upper and lower case, then upgrade again.');
            }
            $conn->execute('DROP TABLE ' . $old);
        }
    }

    private function exists(Connection $conn, string $table): bool
    {
        return $conn->select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            array($table)
        ) !== array();
    }
};
