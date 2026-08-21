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
 * Entitlements and the package catalogue: what credits can be spent on, and what an
 * admin sells them for.
 *
 * t_user_entitlement cascades with the user -- an entitlement without an account means
 * nothing, unlike the ledger and orders, which deliberately outlive the accounts they
 * describe. t_billing_package is a price list, not a ledger: rows are edited and
 * removed by an admin, so it carries no history requirement of its own.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, and the preference seed is INSERT IGNORE
 * against the (s_section, s_name) unique key, so a re-run after an interrupted upgrade
 * is safe.
 */
return new class () implements MigrationInterface {
    /** key => [default value, e_type]; mirrors installer/basic_data.sql. */
    private const PREFERENCES = array(
        'billing_free_posts_per_period' => array('0', 'INTEGER'),
        'billing_period_days'           => array('30', 'INTEGER'),
        'billing_publish_credits'       => array('1', 'INTEGER'),
        'billing_premium_credits'       => array('0', 'INTEGER'),
        'billing_premium_days'          => array('30', 'INTEGER'),
        'billing_currency'              => array('USD', 'STRING'),
        'billing_offline_enabled'       => array('0', 'BOOLEAN'),
        'billing_offline_instructions'  => array('', 'STRING'),
    );

    public function up(Connection $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_user_entitlement ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_user_id INT UNSIGNED NOT NULL,'
            . ' s_feature VARCHAR(64) NOT NULL DEFAULT \'\','
            . ' i_quantity INT NULL,'
            . ' dt_expiration DATETIME NULL,'
            . ' s_source VARCHAR(32) NOT NULL DEFAULT \'purchase\','
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_user_feature (fk_i_user_id, s_feature),'
            . ' INDEX idx_expiration (dt_expiration),'
            . ' FOREIGN KEY (fk_i_user_id) REFERENCES ' . DB_TABLE_PREFIX . 't_user (pk_i_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );

        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_billing_package ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_name VARCHAR(120) NOT NULL DEFAULT \'\','
            . ' i_amount BIGINT NOT NULL DEFAULT 0,'
            . ' s_currency CHAR(3) NOT NULL DEFAULT \'\','
            . ' i_credits INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_position INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' b_enabled TINYINT(1) NOT NULL DEFAULT 1,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' INDEX idx_enabled_position (b_enabled, i_position)'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_general_ci\''
        );

        $table = DB_TABLE_PREFIX . 't_preference';
        foreach (self::PREFERENCES as $name => $spec) {
            [$value, $type] = $spec;
            $conn->execute(
                'INSERT IGNORE INTO ' . $table . ' (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
                array('osclass', $name, $value, $type)
            );
        }
    }
};
