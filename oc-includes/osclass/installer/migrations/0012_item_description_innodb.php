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
 * Convert t_item_description to InnoDB.
 *
 * It was the one MyISAM table in the schema, because a FULLTEXT index needed
 * MyISAM before MySQL 5.6. struct.sql has declared it InnoDB for a while, so a
 * fresh install already gets InnoDB — but the additive schema reconcile on the
 * upgrade path only adds columns, indexes and keys and never changes a storage
 * engine, so every upgraded install kept MyISAM indefinitely.
 *
 * That divergence is not cosmetic. On MyISAM the table has no transactions and
 * no crash recovery, locks at table rather than row level on one of the busiest
 * tables in the schema, and cannot participate in foreign keys. It also changes
 * search results: MyISAM and InnoDB full-text indexes have different default
 * minimum token lengths (ft_min_word_len 4 vs innodb_ft_min_token_size 3) and
 * different stopword lists, so the same query returns different listings
 * depending on whether the install was upgraded or installed fresh.
 *
 * The FULLTEXT index is carried across by the conversion — InnoDB has supported
 * FULLTEXT since MySQL 5.6, which is below the supported floor.
 *
 * Guarded on the current engine so a re-run costs nothing. On a large install
 * the ALTER rewrites the table and can take a while; there is no way to change
 * an engine without that.
 */
return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_item_description';

        $engine = $conn->scalar(
            'SELECT ENGINE FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            array($table)
        );

        // Absent (a fresh install baselines rather than replays this) or already
        // converted: nothing to do.
        if ($engine === null || strtoupper((string)$engine) === 'INNODB') {
            return;
        }

        $conn->execute('ALTER TABLE ' . $table . ' ENGINE=InnoDB');
    }
};
