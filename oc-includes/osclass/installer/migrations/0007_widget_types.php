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
return new class implements MigrationInterface {
    public function up(DBCommandClass $comm): void
    {
        $table = DB_TABLE_PREFIX . 't_widget';

        if (!$this->columnExists($comm, $table, 's_type')) {
            $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN s_type VARCHAR(60) NULL';
            if ($comm->query($sql) === false) {
                throw new RuntimeException('widget types migration: failed to add s_type to t_widget');
            }
        }

        if (!$this->columnExists($comm, $table, 's_config')) {
            $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN s_config TEXT NULL';
            if ($comm->query($sql) === false) {
                throw new RuntimeException('widget types migration: failed to add s_config to t_widget');
            }
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
            throw new RuntimeException('widget types migration: unable to inspect columns of ' . $table);
        }

        $row = $result->row();

        return isset($row['c']) && (int) $row['c'] > 0;
    }
};
