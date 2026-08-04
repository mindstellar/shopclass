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
 * Widget ordering column.
 *
 * Adds i_order to t_widget so widgets within a location can be arranged in an
 * explicit order rather than relying on implicit primary-key order. Existing
 * rows default to 0, which — combined with a primary-key tiebreak in
 * Widget::findByLocation() — preserves their current relative order.
 *
 * The ADD COLUMN is guarded by an information_schema lookup and only runs when
 * i_order is absent, so the step is idempotent and safe to re-run after an
 * interrupted upgrade. The same column is declared in installer/struct.sql for
 * a fresh install, which the runner baselines rather than replays; this
 * migration brings an existing install up to the same state.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        if ($this->columnExists($conn, DB_TABLE_PREFIX . 't_widget', 'i_order')) {
            return;
        }

        $sql = 'ALTER TABLE ' . DB_TABLE_PREFIX . 't_widget'
            . ' ADD COLUMN i_order INT NOT NULL DEFAULT 0';

        $conn->execute($sql);
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
