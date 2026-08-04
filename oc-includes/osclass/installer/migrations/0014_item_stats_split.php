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
 * Split t_item_stats into per-listing totals and a site-wide daily rollup.
 *
 * t_item_stats was keyed (fk_i_item_id, dt_date): one row per listing per day it
 * saw any traffic, carrying seven secondary indexes and pruned by nothing. A
 * listing live two months with daily traffic was sixty rows, so the table grew
 * with views rather than with listings and became the largest and hottest table
 * in the schema. The indexes cost roughly twice what the data did, and six of
 * the seven existed only for the admin reported-listings screen.
 *
 * Nothing ever read a single listing's counts for a single day. Every reader
 * summed the whole history back into one number per listing, and the only
 * consumer of the date dimension at all was the admin reports chart, which
 * aggregates across every listing and groups by date. So the date dimension did
 * not need listing granularity:
 *
 *   t_item_stats        one row per listing, totals, 1:1 with t_item
 *   t_item_stats_daily  one row per (day, write bucket), site-wide
 *
 * i_bucket spreads concurrent writes across eight rows per day. Without it every
 * view on the site would contend on a single row for the current date, which
 * would be a worse hotspot than the per-listing rows this replaces.
 *
 * Both totals and history are carried across, so per-listing view counts and the
 * reports chart read the same after the upgrade as before it.
 *
 * The rebuild is a create-insert-rename rather than an ALTER: changing the
 * primary key rewrites the table anyway, and on an install with millions of rows
 * the grouped insert into a fresh table is far cheaper than an in-place rebuild
 * that carries all eight indexes along with it.
 *
 * Idempotent, and safe to re-run after an interrupted attempt: the guard below
 * returns early once the primary key is narrowed, the scratch table is dropped
 * before use, and the rollup backfill is deterministic so INSERT IGNORE either
 * writes a (date, bucket) row or leaves the identical one already there.
 */
return new class () implements MigrationInterface {
    /** Counter columns, in schema order. */
    private const COUNTERS = array(
        'i_num_views',
        'i_num_spam',
        'i_num_repeated',
        'i_num_bad_classified',
        'i_num_offensive',
        'i_num_expired',
        'i_num_premium_views',
    );

    public function up(Connection $conn): void
    {
        $stats = DB_TABLE_PREFIX . 't_item_stats';
        $daily = DB_TABLE_PREFIX . 't_item_stats_daily';
        $item  = DB_TABLE_PREFIX . 't_item';
        $scratch = DB_TABLE_PREFIX . 't_item_stats_new';

        // How many columns the primary key spans. Absent (the table is gone) or
        // already one column (this ran before): nothing to do.
        $pkColumns = (int)$conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($stats, 'PRIMARY')
        );
        if ($pkColumns !== 2) {
            return;
        }

        $counters = implode(', ', self::COUNTERS);

        // The additive schema reconcile that runs ahead of the migrations creates
        // this from struct.sql, so it is usually already here.
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $daily . ' ('
            . ' dt_date DATE NOT NULL,'
            . ' i_bucket TINYINT UNSIGNED NOT NULL,'
            . ' i_num_views INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_spam INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_repeated INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_bad_classified INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_offensive INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_expired INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_premium_views INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (dt_date, i_bucket)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );

        // Carry the history into the rollup. Bucketing on the listing id is only
        // a way to spread the existing rows deterministically; the write path
        // picks its bucket at random, which is what actually spreads contention.
        $conn->execute(
            'INSERT IGNORE INTO ' . $daily . ' (dt_date, i_bucket, ' . $counters . ')'
            . ' SELECT dt_date, fk_i_item_id % 8, ' . $this->sums('')
            . ' FROM ' . $stats
            . ' GROUP BY dt_date, fk_i_item_id % 8'
        );

        // Built without the foreign key so that adding it after the rename
        // produces the same constraint name a fresh install gets.
        $conn->execute('DROP TABLE IF EXISTS ' . $scratch);
        $conn->execute(
            'CREATE TABLE ' . $scratch . ' ('
            . ' fk_i_item_id INT UNSIGNED NOT NULL,'
            . ' i_num_views INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_spam INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_repeated INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_bad_classified INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_offensive INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_expired INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' i_num_premium_views INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' dt_date DATE NULL,'
            . ' PRIMARY KEY (fk_i_item_id),'
            . ' INDEX i_num_spam (i_num_spam)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );

        // The join to t_item drops rows whose listing is already gone, so a
        // database that lost referential integrity at some point cannot abort the
        // rebuild when the foreign key goes back on below. dt_date stops being
        // part of the key and becomes the last date the listing saw activity.
        $conn->execute(
            'INSERT INTO ' . $scratch . ' (fk_i_item_id, ' . $counters . ', dt_date)'
            . ' SELECT s.fk_i_item_id, ' . $this->sums('s.') . ', MAX(s.dt_date)'
            . ' FROM ' . $stats . ' s'
            . ' INNER JOIN ' . $item . ' i ON i.pk_i_id = s.fk_i_item_id'
            . ' GROUP BY s.fk_i_item_id'
        );

        $conn->execute(
            'RENAME TABLE ' . $stats . ' TO ' . $stats . '_old, ' . $scratch . ' TO ' . $stats
        );
        $conn->execute('DROP TABLE ' . $stats . '_old');

        $conn->execute(
            'ALTER TABLE ' . $stats
            . ' ADD FOREIGN KEY (fk_i_item_id) REFERENCES ' . $item . ' (pk_i_id)'
        );
    }

    /**
     * "SUM(<prefix>i_num_views), SUM(<prefix>i_num_spam), ..." in schema order.
     */
    private function sums(string $prefix): string
    {
        return implode(', ', array_map(
            static fn ($c) => 'SUM(' . $prefix . $c . ')',
            self::COUNTERS
        ));
    }
};
