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
 * Drop the unique key on t_locale.s_short_name. Languages share a short name (en_US and en_AU
 * are both "English"), so a second one could not be installed.
 *
 * Idempotent: it drops only the unique indexes still on the column.
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table   = DB_TABLE_PREFIX . 't_locale';
        $indexes = $conn->select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> ?'
            . ' GROUP BY INDEX_NAME HAVING GROUP_CONCAT(COLUMN_NAME) = ?',
            array($table, 'PRIMARY', 's_short_name')
        );
        foreach ($indexes as $index) {
            $conn->execute('DROP INDEX `' . $index['INDEX_NAME'] . '` ON ' . $table);
        }
    }
};
