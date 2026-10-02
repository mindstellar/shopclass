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
use mindstellar\migration\SchemaProbes;

/**
 * Add t_ban_rule.s_scope ('all', or 'messages' for a rule that only blocks contact mail)
 * and t_ban_rule.dt_expires (NULL never ends). Existing rules keep blocking everything.
 *
 * @title Ban rules can end, and can block messages only
 */
return new class () implements MigrationInterface {
    use SchemaProbes;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     */
    public function up(Connection $conn): void
    {
        $table = DB_TABLE_PREFIX . 't_ban_rule';
        if (!$this->tableExists($conn, $table)) {
            return;
        }
        if (!$this->columnExists($conn, $table, 's_scope')) {
            $conn->execute("ALTER TABLE " . $table . " ADD COLUMN s_scope VARCHAR(20) NOT NULL DEFAULT 'all' AFTER s_email");
        }
        if (!$this->columnExists($conn, $table, 'dt_expires')) {
            $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN dt_expires DATETIME NULL DEFAULT NULL AFTER s_scope');
        }
    }
};
