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
 * Add t_key_value, the shared key-value store (REST API Idempotency-Keys keep their answers there
 * for a day). A new table, so nothing existing is touched; safe to re-run. A development
 * install that made the earlier t_api_idempotency, which never shipped, has it dropped.
 *
 * @title REST API repeatable writes
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
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_key_value ('
            . ' s_group VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . ' s_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,'
            . ' s_value MEDIUMTEXT NULL,'
            . ' s_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . ' dt_created DATETIME NOT NULL,'
            . ' dt_updated DATETIME NULL,'
            . ' dt_expires DATETIME NULL,'
            . ' PRIMARY KEY (s_group, s_key),'
            . ' INDEX idx_expires (dt_expires)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );

        $old = DB_TABLE_PREFIX . 't_api_idempotency';
        if ($this->tableExists($conn, $old) && $this->columnExists($conn, $old, 's_fingerprint')) {
            $conn->execute('DROP TABLE ' . $old);
        }
    }
};
