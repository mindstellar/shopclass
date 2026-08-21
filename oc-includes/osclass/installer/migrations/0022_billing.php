<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

/**
 * Billing foundation: the credit wallet, its append-only ledger, and payment orders,
 * plus an expiry date for premium listings.
 *
 * Core owns entitlements; a gateway plugin owns money and reports that an order is
 * paid. Credits are the unit in between, so a gateway never has to enumerate the
 * features it supports and core stays testable with no gateway installed.
 *
 * t_item.dt_premium_expiration is what makes a premium listing sellable at all:
 * b_premium is a boolean with no end date, so "featured for 30 days" could not be
 * expressed and premium could only be granted permanently by an admin. It is a column
 * rather than a joined upgrades table because the listing-visibility predicate
 * (Item::liveConditions) is in every search, category and home query, and a join there
 * would land on the hottest query in the product.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, and the ADD COLUMN is guarded by an
 * information_schema lookup, so re-running after an interrupted upgrade is safe. The
 * same objects are declared in installer/struct.sql for a fresh install, which the
 * runner baselines rather than replays; this migration brings an existing install to
 * the same state.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $item = DB_TABLE_PREFIX . 't_item';

        if (!$this->columnExists($conn, $item, 'dt_premium_expiration')) {
            $conn->execute('ALTER TABLE ' . $item . ' ADD COLUMN dt_premium_expiration DATETIME NULL');
        }

        // Cached balance, one row per user. The ledger is the source of truth; this
        // keeps a balance read off a SUM() and is the row a debit locks. Cascades with
        // the user, because a balance without an account means nothing.
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_billing_wallet ('
            . ' fk_i_user_id INT UNSIGNED NOT NULL,'
            . ' i_balance BIGINT NOT NULL DEFAULT 0,'
            . ' dt_mod_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (fk_i_user_id),'
            . ' FOREIGN KEY (fk_i_user_id) REFERENCES ' . DB_TABLE_PREFIX . 't_user (pk_i_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );

        // Append-only: never updated, never deleted -- a refund is a new negative row.
        // No foreign key to t_user on purpose; the audit trail has to outlive the
        // account it describes.
        //
        // s_idempotency_key is UNIQUE because gateways retry webhooks. A replayed
        // callback must insert nothing rather than credit twice.
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_billing_ledger ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_user_id INT UNSIGNED NOT NULL,'
            . ' i_amount BIGINT NOT NULL,'
            . ' i_balance_after BIGINT NOT NULL,'
            . ' s_reason VARCHAR(32) NOT NULL DEFAULT \'\','
            . ' s_ref_type VARCHAR(32) NULL,'
            . ' i_ref_id INT UNSIGNED NULL,'
            . ' s_idempotency_key VARCHAR(191) NULL,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uq_idempotency (s_idempotency_key),'
            . ' INDEX idx_user_date (fk_i_user_id, dt_date),'
            . ' INDEX idx_ref (s_ref_type, i_ref_id)'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );

        // i_amount is in micros (value x 1000000), matching t_item.i_price -- money is
        // never stored as a float.
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_billing_order ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_user_id INT UNSIGNED NOT NULL,'
            . ' s_gateway VARCHAR(64) NOT NULL DEFAULT \'\','
            . ' s_external_ref VARCHAR(191) NULL,'
            . ' i_amount BIGINT NOT NULL DEFAULT 0,'
            . ' s_currency CHAR(3) NOT NULL DEFAULT \'\','
            . ' i_credits INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' s_status VARCHAR(16) NOT NULL DEFAULT \'pending\','
            . ' s_meta TEXT NULL,'
            . ' dt_date DATETIME NOT NULL,'
            . ' dt_paid_date DATETIME NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uq_gateway_ref (s_gateway, s_external_ref),'
            . ' INDEX idx_user_status (fk_i_user_id, s_status),'
            . ' INDEX idx_date (dt_date)'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );
    }

    /**
     * Whether $column already exists on $table in the current database.
     */
    private function columnExists(Connection $conn, string $table, string $column): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND COLUMN_NAME = ?',
            array($table, $column)
        );

        return (int) $count > 0;
    }
};
