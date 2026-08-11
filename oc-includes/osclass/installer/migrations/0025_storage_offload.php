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
 * The storage-offload tables, which shipped in 6.0.0 declared only in struct.sql.
 *
 * Nothing ever migrated them, so an install upgrading from 5.x received them purely
 * by way of the schema reconciler reading struct.sql and noticing they were absent.
 * That worked, and hid the fact that the migration sequence could not rebuild the
 * schema on its own -- which tests/schema-drift.php now requires it to.
 *
 * Writing them as a migration this late is safe because both steps are guarded and
 * every install that has them already got exactly this shape from struct.sql: on a
 * 6.x install this is a no-op, and on a 5.x one it does what the reconciler used to.
 *
 * s_storage names which backend holds a resource, defaulting to 'local' so rows
 * written before any offload existed keep pointing at the filesystem.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_storage_queue ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_type VARCHAR(20) NOT NULL,'
            . ' s_storage VARCHAR(30) NOT NULL,'
            . ' s_payload TEXT NOT NULL,'
            . " s_status VARCHAR(10) NOT NULL DEFAULT 'pending',"
            . ' i_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' s_last_error VARCHAR(250) NULL,'
            . ' s_worker VARCHAR(30) NULL,'
            . ' dt_next_run DATETIME NOT NULL,'
            . ' dt_locked DATETIME NULL,'
            . ' dt_created DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_status_next (s_status, dt_next_run)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );

        $resources = DB_TABLE_PREFIX . 't_item_resource';
        if (!$this->columnExists($conn, $resources, 's_storage')) {
            $conn->execute(
                'ALTER TABLE ' . $resources
                . " ADD COLUMN s_storage VARCHAR(30) NOT NULL DEFAULT 'local'"
            );
        }
    }

    /**
     * Whether $column already exists on $table in the current database.
     */
    private function columnExists(Connection $conn, string $table, string $column): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND COLUMN_NAME = ?',
            array($table, $column)
        );

        return (int) $count > 0;
    }
};
