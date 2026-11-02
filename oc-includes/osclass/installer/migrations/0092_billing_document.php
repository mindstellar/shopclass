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
 * Add t_billing_document, the numbered invoices and credit notes of billing orders.
 * Safe to re-run: the table is made only when missing.
 *
 * @title Invoices and credit notes table
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
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_billing_document ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . ' s_number VARCHAR(40) NOT NULL,'
            . ' dt_date DATETIME NOT NULL,'
            . ' fk_i_order_id INT UNSIGNED NOT NULL,'
            . ' i_total BIGINT NOT NULL,'
            . ' s_currency CHAR(3) NOT NULL,'
            . ' s_data MEDIUMTEXT NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uq_type_number (s_type, s_number),'
            . ' INDEX idx_date (dt_date),'
            . ' INDEX idx_order (fk_i_order_id))'
            . " ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
    }
};
