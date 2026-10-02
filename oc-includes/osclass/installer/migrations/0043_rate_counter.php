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
 * Add t_rate_counter: one row per key per time window, for RateLimit. A new, empty table,
 * so nothing existing is touched. Idempotent: CREATE TABLE IF NOT EXISTS.
 */
return new class () implements MigrationInterface {
    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 't_rate_counter ('
            . ' s_bucket VARCHAR(100) NOT NULL,'
            . ' i_window INT UNSIGNED NOT NULL,'
            . ' i_expires INT UNSIGNED NOT NULL,'
            . ' i_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (s_bucket, i_window),'
            . ' INDEX idx_expires (i_expires)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
    }
};
