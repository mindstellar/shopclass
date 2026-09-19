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
 * `idx_s_content_type` led on `pk_i_id`, the table's own auto-increment key. An index is only
 * read when a query filters on its first column, and a filter on `pk_i_id` has already found
 * its single row — through the primary key, which is faster. So it was never used, on any
 * install, while costing a write on every insert and update.
 *
 * Only two queries touch `s_content_type`: `ItemsDataTable` filters `fk_i_item_id` alongside it
 * and takes the `fk_i_item_id` index, and `Search` uses a leading wildcard, which no index can
 * serve. The declaration is gone from `struct.sql`; this removes it from existing databases.
 *
 * Dropping a secondary index is in-place on InnoDB, so it neither rebuilds the table nor blocks
 * writes. The foreign key on `fk_i_item_id` keeps its own covering index and is unaffected.
 *
 * Old installs carry it under a different shape, `(fk_i_item_id, s_content_type)`, which is just
 * as unused; it goes by name either way.
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
