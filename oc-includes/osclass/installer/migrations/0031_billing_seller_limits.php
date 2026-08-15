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
 * Seed the preferences behind the three optional seller-limit entitlements: a raised
 * photo cap, a waived flood wait, and extra listing runtime. No schema change -- each
 * one reads t_user_entitlement (already created by 0029_billing_entitlements.php)
 * through Entitlements::capacity()/has(), keyed by its own feature id.
 *
 * Every one ships disabled, and *_enabled/*_credits are separate preferences for the
 * same reason as the item upgrades in 0030: an enabled limit priced at 0 credits is
 * free to every seller, not switched off. An upgraded install behaves identically
 * until an admin turns one on.
 *
 * Idempotent: INSERT IGNORE against the (s_section, s_name) unique key, so a re-run
 * after an interrupted upgrade is safe.
 */
return new class () implements MigrationInterface {
    /** key => [default value, e_type]; mirrors installer/basic_data.sql. */
    private const PREFERENCES = array(
        'billing_photos_enabled'  => array('0', 'BOOLEAN'),
        'billing_photos_credits'  => array('0', 'INTEGER'),
        'billing_photos_quantity' => array('10', 'INTEGER'),
        'billing_no_wait_enabled' => array('0', 'BOOLEAN'),
        'billing_no_wait_credits' => array('0', 'INTEGER'),
        'billing_no_wait_days'    => array('30', 'INTEGER'),
        'billing_runtime_enabled' => array('0', 'BOOLEAN'),
        'billing_runtime_credits' => array('0', 'INTEGER'),
        'billing_runtime_days'    => array('30', 'INTEGER'),
    );

    public function up(Connection $conn): void
    {
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
