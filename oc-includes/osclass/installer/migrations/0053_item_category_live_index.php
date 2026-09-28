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
 * Add t_item.idx_category_live, which serves the category search and its count. Skipped
 * when an index on the same columns already exists under any name.
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    private const COLUMNS = array('fk_i_category_id', 'b_enabled', 'b_active', 'b_spam', 'dt_pub_date', 'dt_expiration', 'b_premium');

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_item';
        if ($this->indexExists($conn, $table, 'idx_category_live')
            || $this->indexesOnColumns($conn, $table, self::COLUMNS) !== array()
        ) {
            return;
        }
        $conn->execute(
            'ALTER TABLE ' . $table . ' ADD INDEX idx_category_live (' . implode(', ', self::COLUMNS) . '), ALGORITHM=INPLACE, LOCK=NONE'
        );
    }
};
