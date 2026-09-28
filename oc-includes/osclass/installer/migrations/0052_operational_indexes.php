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
 * Indexes for the log and search purges, the alert cron, the admin user list and the
 * expiry cron. An index already present on the same columns under another name counts,
 * so a hand-added one is not duplicated.
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /** [table, index name, columns in order]. Mirrors struct.sql. */
    private const INDEXES = array(
        array('t_log', 'idx_date', array('dt_date')),
        array('t_latest_searches', 'idx_date', array('d_date')),
        array('t_alerts', 'idx_type', array('e_type', 'b_active', 'dt_unsub_date')),
        array('t_alerts', 'idx_user', array('fk_i_user_id')),
        array('t_alerts', 'idx_email', array('s_email')),
        array('t_user', 'idx_reg_date', array('dt_reg_date')),
        array('t_item', 'idx_expiration', array('dt_expiration')),
    );

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        foreach (self::INDEXES as [$table, $index, $columns]) {
            $table = DB_TABLE_PREFIX . $table;
            if (!$this->tableExists($conn, $table)
                || $this->indexExists($conn, $table, $index)
                || $this->indexesOnColumns($conn, $table, $columns) !== array()
            ) {
                continue;
            }
            $conn->execute(
                'ALTER TABLE ' . $table . ' ADD INDEX ' . $index . ' (' . implode(', ', $columns) . '), ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }
};
