<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\currency\CurrencyCode;
use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;
use mindstellar\migration\SchemaProbes;

/**
 * Add t_currency.i_decimals and set every currency to its ISO 4217 digits. Safe to re-run: the
 * column is added only when missing, and the values are set, never added.
 *
 * @title Decimals per currency
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
        $table = DB_TABLE_PREFIX . 't_currency';
        if (!$this->columnExists($conn, $table, 'i_decimals')) {
            $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN i_decimals TINYINT UNSIGNED NOT NULL DEFAULT 2');
        }
        // Only rows still at the column default change, so a re-run keeps an admin's own value.
        foreach ($conn->select('SELECT pk_c_code FROM ' . $table . ' WHERE i_decimals = 2') as $row) {
            $iso = CurrencyCode::decimals((string) $row['pk_c_code']);
            if ($iso !== 2) {
                $conn->execute(
                    'UPDATE ' . $table . ' SET i_decimals = ? WHERE pk_c_code = ? AND i_decimals = 2',
                    array($iso, $row['pk_c_code'])
                );
            }
        }
    }
};
