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
 * The sign-out stamp on users and admins (i_auth_stamp), which "sign out of all devices"
 * raises to end every sign-in. Added last with a default, so no row is rewritten; safe to
 * re-run.
 *
 * @title Sign out of all devices
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
        foreach (['t_user', 't_admin'] as $name) {
            $table = DB_TABLE_PREFIX . $name;
            if ($this->tableExists($conn, $table) && !$this->columnExists($conn, $table, 'i_auth_stamp')) {
                $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN i_auth_stamp INT UNSIGNED NOT NULL DEFAULT 0');
            }
        }
    }
};
