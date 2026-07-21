<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

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
return new class implements MigrationInterface {
    public function up(DBCommandClass $comm): void
    {
        if ($this->columnExists($comm, DB_TABLE_PREFIX . 't_widget', 'i_order')) {
            return;
        }

        $sql = 'ALTER TABLE ' . DB_TABLE_PREFIX . 't_widget'
            . ' ADD COLUMN i_order INT NOT NULL DEFAULT 0';

        if ($comm->query($sql) === false) {
            throw new RuntimeException('widget order migration: failed to add i_order to t_widget');
        }
    }

    /**
     * Whether $column already exists on $table in the current database.
     */
    private function columnExists(DBCommandClass $comm, string $table, string $column): bool
    {
        $sql = 'SELECT COUNT(*) AS c FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ' . $comm->escape($table)
            . ' AND COLUMN_NAME = ' . $comm->escape($column);

        $result = $comm->query($sql);
        if (!is_object($result)) {
            throw new RuntimeException('widget order migration: unable to inspect columns of ' . $table);
        }

        $row = $result->row();

        return isset($row['c']) && (int) $row['c'] > 0;
    }
};
