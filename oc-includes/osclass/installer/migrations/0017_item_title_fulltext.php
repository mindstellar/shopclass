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
 * Add a title-only FULLTEXT index to t_item_description.
 *
 * Search now ranks a title hit above a description-only hit by scoring a
 * separate MATCH(s_title) alongside the existing combined index. That MATCH
 * needs its own FULLTEXT index on s_title — without it the query errors with
 * "Can't find FULLTEXT index matching the column list", so this index is
 * required, not an optimisation.
 *
 * Guarded on the index name so a re-run costs nothing. On a large install the
 * ALTER builds the index and can take a while; FULLTEXT index creation always
 * scans the table.
 */
return new class implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_item_description';

        $exists = $conn->scalar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($table, 's_title')
        );

        if ((int)$exists > 0) {
            return;
        }

        $conn->execute('ALTER TABLE ' . $table . ' ADD FULLTEXT s_title (s_title)');
    }
};
