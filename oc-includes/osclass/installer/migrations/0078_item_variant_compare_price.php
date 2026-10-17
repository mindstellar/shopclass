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
 * Add i_compare_price to t_item_variant, the "was" price shown struck through beside the price.
 * Safe to re-run: the column is added only when missing.
 *
 * @title Variant compare price
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $found = $conn->select(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            array(DB_TABLE_PREFIX . 't_item_variant', 'i_compare_price')
        );
        if ($found === array()) {
            $conn->execute('ALTER TABLE ' . DB_TABLE_PREFIX . 't_item_variant ADD COLUMN i_compare_price BIGINT(20) NULL AFTER i_price');
        }
    }
};
