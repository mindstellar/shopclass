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
 * Adds the listing-slot free quota ("N listings live at once") as the seller's
 * one and only posting limit, and retires the rolling-window preferences
 * migration 0029 seeded for the free-posts-per-period quota that limit replaces
 * -- that quota was never released and is being dropped before it ships.
 *
 * Also drops the idx_user_first_pub index migration 0033 added on
 * (fk_i_user_id, dt_first_pub_date) -- nothing reads it once the rolling window
 * is gone -- and adds (fk_i_user_id, dt_expiration) instead, which is what
 * counting a seller's live listings actually filters on
 * (Entitlements::liveListings()). t_item.dt_first_pub_date itself stays: a bump
 * overwrites dt_pub_date, so first-publication would otherwise be
 * unrecoverable, and a nullable, unindexed datetime costs nothing to keep.
 *
 * billing_free_live_listings defaults to 0 (unlimited) -- an existing install
 * upgrading sees no behaviour change until an admin sets a number.
 *
 * Idempotent: the preference seed is INSERT IGNORE against the (s_section,
 * s_name) unique key, the retired-preference deletes match nothing on a second
 * run, and both index changes are guarded by an information_schema check.
 */
return new class () implements MigrationInterface {
    /** key => [default value, e_type]; mirrors installer/basic_data.sql. */
    private const PREFERENCES = array(
        'billing_free_live_listings' => array('0', 'INTEGER'),
        'billing_slot_enabled'       => array('0', 'BOOLEAN'),
        'billing_slot_credits'       => array('0', 'INTEGER'),
        'billing_slot_quantity'      => array('1', 'INTEGER'),
    );

    /**
     * Rows migration 0029 seeded to price the rolling-window quota, plus
     * billing_quota_mode -- an earlier, never-released draft of this migration
     * seeded it to select between two models. Only one model exists now, so any
     * of the three left over from that draft are removed too.
     */
    private const RETIRED_PREFERENCES = array(
        'billing_free_posts_per_period',
        'billing_period_days',
        'billing_publish_credits',
        'billing_quota_mode',
    );

    public function up(Connection $conn): void
    {
        $this->seedPreferences($conn);
        $this->removeRetiredPreferences($conn);
        $this->reindexItemByExpiration($conn);
    }

    private function seedPreferences(Connection $conn): void
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

    private function removeRetiredPreferences(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_preference';
        foreach (self::RETIRED_PREFERENCES as $name) {
            $conn->execute(
                'DELETE FROM ' . $table . ' WHERE s_section = ? AND s_name = ?',
                array('osclass', $name)
            );
        }
    }

    private function reindexItemByExpiration(Connection $conn): void
    {
        $item = DB_TABLE_PREFIX . 't_item';

        if ($this->indexExists($conn, $item, 'idx_user_first_pub')) {
            $conn->execute('ALTER TABLE ' . $item . ' DROP INDEX idx_user_first_pub');
        }

        if (!$this->indexExists($conn, $item, 'idx_user_expiration')) {
            $conn->execute(
                'CREATE INDEX idx_user_expiration ON ' . $item . ' (fk_i_user_id, dt_expiration)'
            );
        }
    }

    /**
     * Whether an index (unique or not) named $index already exists on $table.
     */
    private function indexExists(Connection $conn, string $table, string $index): bool
    {
        $count = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME = ?'
            . ' AND INDEX_NAME = ?',
            array($table, $index)
        );

        return (int) $count > 0;
    }
};
