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
 * Widget type + config columns.
 *
 * Adds s_type and s_config to t_widget so a widget row can point at a registered
 * widget type (rendered by a callable) instead of storing raw content. A NULL
 * s_type marks a legacy stored-content row, which keeps rendering byte-for-byte
 * as before; s_config holds the type's JSON-encoded configuration.
 *
 * Each ADD COLUMN is guarded by an information_schema lookup and only runs when
 * the column is absent, so the step is idempotent and safe to re-run after an
 * interrupted upgrade. The same columns are declared in installer/struct.sql for
 * a fresh install, which the runner baselines rather than replays; this
 * migration brings an existing install up to the same state.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_widget';

        if (!$this->columnExists($conn, $table, 's_type')) {
            $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN s_type VARCHAR(60) NULL';
            $conn->execute($sql);
        }

        if (!$this->columnExists($conn, $table, 's_config')) {
            $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN s_config TEXT NULL';
            $conn->execute($sql);
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
