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
 * Normalise t_item_report_log to utf8mb4.
 *
 * The table was created (by migration 0003 and, historically, by a third-party
 * classifieds theme's own IF NOT EXISTS import) with DEFAULT CHARSET 'UTF8'
 * (utf8mb3), so that whichever side created it first, both matched byte-for-byte.
 * Everything else in the schema standardises on utf8mb4 (migration 0001), which
 * left this one table as the sole utf8mb3 outlier — a fresh install (struct.sql,
 * now utf8mb4) and an upgraded install therefore diverged on it (schema drift).
 *
 * This converts the table to utf8mb4 wherever it exists, so every install
 * converges regardless of who created it or when. utf8mb4 is a strict superset of
 * utf8mb3, so no stored data changes and a theme still reads/writes the same rows.
 * struct.sql declares the table utf8mb4 for fresh installs; this brings existing
 * installs (and any theme-created copy) to the same state.
 *
 * Idempotent: skips when the table is absent, and CONVERT TO on an
 * already-utf8mb4 table is a no-op.
 */
return new class implements MigrationInterface {
    public function up(DBCommandClass $comm): void
    {
        $table = DB_TABLE_PREFIX . 't_item_report_log';
        if (!$this->tableExists($comm, $table)) {
            return;
        }

        $sql = 'ALTER TABLE ' . $table . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci';
        if ($comm->query($sql) === false) {
            throw new RuntimeException('report log utf8mb4 migration: failed to convert ' . $table);
        }
    }

    /**
     * Whether $table exists in the current database.
     */
    private function tableExists(DBCommandClass $comm, string $table): bool
    {
        $sql = 'SELECT COUNT(*) AS c FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ' . $comm->escape($table);

        $result = $comm->query($sql);
        if (!is_object($result)) {
            throw new RuntimeException('report log utf8mb4 migration: unable to inspect tables');
        }

        $row = $result->row();

        return isset($row['c']) && (int) $row['c'] > 0;
    }
};
