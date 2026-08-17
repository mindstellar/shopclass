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
 * One table for every item upgrade -- bump, highlight, urgent, and whatever a site
 * registers later -- rather than a column per upgrade or a JSON blob on t_item. The
 * unique key on (fk_i_item_id, s_upgrade) is the point: one row per item per upgrade,
 * extended on repurchase, never duplicated, and the expiry sweep never reconciles a
 * second row for the same pair. Cascades with the item -- an upgrade on a deleted
 * listing means nothing.
 *
 * Every upgrade ships disabled, and price and "enabled" are deliberately separate
 * preferences: an enabled upgrade priced at 0 credits is free to every seller, not
 * switched off.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, and the preference seed is INSERT IGNORE
 * against the (s_section, s_name) unique key, so a re-run after an interrupted upgrade
 * is safe.
 */
return new class () implements MigrationInterface {
    /** key => [default value, e_type]; mirrors installer/basic_data.sql. */
    private const PREFERENCES = array(
        'billing_bump_enabled'        => array('0', 'BOOLEAN'),
        'billing_bump_credits'        => array('0', 'INTEGER'),
        'billing_bump_cooldown_hours' => array('24', 'INTEGER'),
        'billing_highlight_enabled'   => array('0', 'BOOLEAN'),
        'billing_highlight_credits'   => array('0', 'INTEGER'),
        'billing_highlight_days'      => array('30', 'INTEGER'),
        'billing_urgent_enabled'      => array('0', 'BOOLEAN'),
        'billing_urgent_credits'      => array('0', 'INTEGER'),
        'billing_urgent_days'         => array('7', 'INTEGER'),
    );

    public function up(Connection $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_item_upgrade ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            . ' s_upgrade VARCHAR(64) NOT NULL DEFAULT \'\','
            . ' dt_expiration DATETIME NULL,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uq_item_upgrade (fk_i_item_id, s_upgrade),'
            . ' INDEX idx_expiration (dt_expiration),'
            . ' FOREIGN KEY (fk_i_item_id) REFERENCES ' . DB_TABLE_PREFIX . 't_item (pk_i_id) ON DELETE CASCADE'
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
