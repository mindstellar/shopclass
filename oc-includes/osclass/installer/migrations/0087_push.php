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
 * Add the `enabled_push` switch for browser notifications (off). Safe to re-run: an existing
 * value is never overwritten.
 *
 * @title Browser notifications switch
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
            'INSERT IGNORE INTO ' . DB_TABLE_PREFIX . 't_preference (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)',
            array('osclass', 'enabled_push', '0', 'BOOLEAN')
        );
    }
};
