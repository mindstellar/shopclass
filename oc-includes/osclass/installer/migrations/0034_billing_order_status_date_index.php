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
 * t_billing_order has no index leading with s_status, so three of the four counts
 * Orders::summary() runs for the admin orders page (all/paid/pending, filtered on
 * status alone or combined with a status the caller picked) are table scans --
 * idx_user_status can only be used when fk_i_user_id is also in the WHERE clause, and
 * a plain "WHERE s_status = ?" cannot use a prefix that starts with a different
 * column. Orders::search()'s ORDER BY dt_date DESC, pk_i_id DESC also filesorts for
 * the same reason once a status filter is applied.
 *
 * (s_status, dt_date) covers both: an equality match on status followed by a range
 * scan already in dt_date order satisfies the ORDER BY without a filesort.
 *
 * Idempotent: guarded by an information_schema lookup, so a re-run after an
 * interrupted upgrade is safe.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_billing_order';

        if (!$this->indexExists($conn, $table, 'idx_status_date')) {
            $conn->execute('CREATE INDEX idx_status_date ON ' . $table . ' (s_status, dt_date)');
        }
    }

    /**
     * Whether an index (unique or not) named $index already exists on $table.
     */
    private function indexExists(Connection $conn, string $table, string $index): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND INDEX_NAME = ?',
            array($table, $index)
        );

        return (int) $count > 0;
    }
};
