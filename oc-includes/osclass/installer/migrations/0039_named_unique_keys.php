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
 * `struct.sql` used to declare these unique keys without a name, so MySQL named the first
 * one after its column and every schema sync that matched keys by name re-added it under the
 * next free auto-name. Old installs carry the result: `s_email` plus `s_email_2 … s_email_8`,
 * eight identical unique indexes costing a write each on every insert and update.
 *
 * The keys are named in `struct.sql` now; this brings existing databases to the same shape —
 * one index per column set, under the declared name, with the copies dropped.
 *
 * Idempotent: it reads what is on the table and only adds or drops what differs, so a re-run
 * after an interrupted upgrade is safe.
 */
return new class () implements MigrationInterface {
    /** Table => [index name, columns in order]. Mirrors the UNIQUE KEYs in struct.sql. */
    private const KEYS = array(
        't_locale'     => array('uk_locale_short_name', array('s_short_name')),
        't_currency'   => array('uk_currency_name', array('s_name')),
        't_admin'      => array('uk_admin_username', array('s_username')),
        't_admin#2'    => array('uk_admin_email', array('s_email')),
        't_user'       => array('uk_user_email', array('s_email')),
        't_preference' => array('uk_preference_section_name', array('s_section', 's_name')),
        't_migration'  => array('uk_migration_name', array('s_migration')),
    );

    /**
     * Give each unique key its declared name and drop the duplicates left by the old syncs.
     *
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        foreach (self::KEYS as $key => [$index, $columns]) {
            // The map is keyed by table, and t_admin owns two of these keys.
            $table   = DB_TABLE_PREFIX . explode('#', $key)[0];
            $present = $this->uniqueIndexesOn($conn, $table, $columns);

            if ($present === array()) {
                continue; // The table or the key is not there; nothing to rename.
            }

            // An install whose primary key already covers these columns needs no unique key
            // beside it; the copies still go.
            $coveredByPrimary = $this->primaryCovers($conn, $table, $columns);

            // Add the named one first, so the column is never left without a unique key —
            // a foreign key pointing at it would refuse the drop below.
            if (!$coveredByPrimary && !in_array($index, $present, true)) {
                $conn->execute(
                    'CREATE UNIQUE INDEX ' . $index . ' ON ' . $table . ' (' . implode(', ', $columns) . ')'
                );
            }

            foreach ($present as $existing) {
                if ($existing !== $index || $coveredByPrimary) {
                    $conn->execute('DROP INDEX ' . $existing . ' ON ' . $table);
                }
            }
        }
    }

    /**
     * Whether the table's primary key is exactly $columns, in that order.
     *
     * @param Connection        $conn
     * @param string            $table
     * @param array<int,string> $columns
     *
     * @return bool
     * @throws \mindstellar\database\DbException
     */
    private function primaryCovers(Connection $conn, string $table, array $columns): bool
    {
        $cols = $conn->scalar(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($table, 'PRIMARY')
        );

        return (string) $cols === implode(',', $columns);
    }

    /**
     * Every unique index on $table covering exactly $columns, in that order, primary excluded.
     *
     * @param Connection        $conn
     * @param string            $table
     * @param array<int,string> $columns
     *
     * @return array<int,string> index names
     * @throws \mindstellar\database\DbException
     */
    private function uniqueIndexesOn(Connection $conn, string $table, array $columns): array
    {
        $rows = $conn->select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            . ' AND NON_UNIQUE = 0 AND INDEX_NAME <> ?'
            . ' GROUP BY INDEX_NAME',
            array($table, 'PRIMARY')
        );

        $wanted = implode(',', $columns);
        $names  = array();
        foreach ($rows as $row) {
            if (($row['cols'] ?? '') === $wanted) {
                $names[] = (string) $row['INDEX_NAME'];
            }
        }

        return $names;
    }
};
