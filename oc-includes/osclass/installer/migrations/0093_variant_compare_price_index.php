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
 * Index t_item_variant.i_compare_price, so the on-sale filter finds the variants on sale without
 * reading every listing's variants. Added only when missing, without locking the table; safe to re-run.
 *
 * @title Speed up the on-sale filter
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_item_variant';
        if (!$this->tableExists($conn, $table)
            || $this->indexExists($conn, $table, 'idx_compare_price')
            || $this->indexesOnColumns($conn, $table, ['i_compare_price']) !== []
        ) {
            return;
        }
        $conn->execute('ALTER TABLE ' . $table . ' ADD INDEX idx_compare_price (i_compare_price), ALGORITHM=INPLACE, LOCK=NONE');
    }
};
