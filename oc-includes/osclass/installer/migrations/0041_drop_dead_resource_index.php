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

/**
 * Drops the unused `idx_s_content_type` index (in either shape it has shipped under) from
 * `t_item_resource`; no query ever benefited from it. Dropping a secondary index is in-place on
 * InnoDB, so it neither rebuilds the table nor blocks writes.
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_item_resource';

        $present = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($table, 'idx_s_content_type')
        );

        if ((int) $present === 0) {
            return;
        }

        $conn->execute('DROP INDEX idx_s_content_type ON ' . $table);
    }
};
