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
 * Add i_rating to t_item_comment, a 1 to 5 star rating a comment may carry. NULL means no rating.
 * Safe to re-run: the column is added only when missing.
 *
 * @title Comment rating
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $found = $conn->select(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            array(DB_TABLE_PREFIX . 't_item_comment', 'i_rating')
        );
        if ($found === array()) {
            $conn->execute('ALTER TABLE ' . DB_TABLE_PREFIX . 't_item_comment ADD COLUMN i_rating TINYINT UNSIGNED NULL AFTER fk_i_user_id');
        }
    }
};
