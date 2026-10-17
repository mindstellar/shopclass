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
 * Add t_item_variant, the rows that make a listing buyable (SKU, stock, price, weight, shipping
 * class, options). Safe to re-run: the table is made only when missing.
 *
 * @title Listing variants table
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
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_item_variant ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            . ' s_sku VARCHAR(64) NULL,'
            . ' s_options TEXT NULL,'
            . ' i_price BIGINT(20) NULL,'
            . ' i_stock INT NULL,'
            . ' i_weight INT UNSIGNED NULL,'
            . ' s_shipping_class VARCHAR(40) NULL,'
            . ' fk_i_resource_id INT UNSIGNED NULL,'
            . ' i_position SMALLINT NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (pk_i_id), INDEX idx_item (fk_i_item_id), INDEX idx_sku (s_sku))'
            . " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
    }
};
