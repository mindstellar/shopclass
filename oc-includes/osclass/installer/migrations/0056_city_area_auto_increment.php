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
use mindstellar\migration\SchemaProbes;

/**
 * Give t_city_area.pk_i_id AUTO_INCREMENT, so callers can insert a city area without picking
 * an id themselves. It runs with foreign_key_checks off for the session, since MySQL/MariaDB
 * refuse this MODIFY while two tables still reference the column.
 *
 * @title Let new city areas get their own ID
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_city_area';
        if (!$this->tableExists($conn, $table) || $this->hasAutoIncrement($conn, $table)) {
            return;
        }

        $checks = (int) $conn->scalar('SELECT @@SESSION.foreign_key_checks');
        $conn->execute('SET SESSION foreign_key_checks = 0');
        try {
            $conn->execute('ALTER TABLE ' . $table . ' MODIFY pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT');
        } finally {
            $conn->execute('SET SESSION foreign_key_checks = ' . $checks);
        }
    }

    /**
     * @param Connection $conn
     * @param string     $table
     *
     * @return bool
     */
    private function hasAutoIncrement(Connection $conn, string $table): bool
    {
        return (int) $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'pk_i_id' AND EXTRA LIKE '%auto_increment%'",
            array($table)
        ) > 0;
    }
};
