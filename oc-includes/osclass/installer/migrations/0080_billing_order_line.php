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
 * Add t_billing_order_line, the goods, shipping, tax, discount and fee lines of a billing order.
 * Safe to re-run: the table is made only when missing.
 *
 * @title Billing order lines table
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_billing_order_line ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_order_id INT UNSIGNED NOT NULL,'
            . " s_kind VARCHAR(16) NOT NULL DEFAULT 'item',"
            . ' fk_i_item_id INT UNSIGNED NULL,'
            . ' fk_i_variant_id INT UNSIGNED NULL,'
            . ' s_title VARCHAR(200) NOT NULL,'
            . ' s_options TEXT NULL,'
            . ' s_sku VARCHAR(64) NULL,'
            . ' i_qty INT UNSIGNED NOT NULL DEFAULT 1,'
            . ' i_unit BIGINT NOT NULL DEFAULT 0,'
            . ' i_amount BIGINT NOT NULL DEFAULT 0,'
            . ' s_meta TEXT NULL,'
            . ' PRIMARY KEY (pk_i_id), INDEX idx_order (fk_i_order_id), INDEX idx_item (fk_i_item_id))'
            . " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
    }
};
