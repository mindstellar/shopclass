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
 * Add t_admin.s_2fa, an administrator's two-step sign-in settings as JSON; NULL is off.
 * Guarded, so a re-run is safe.
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
        $admin = DB_TABLE_PREFIX . 't_admin';
        if (!$this->columnExists($conn, $admin, 's_2fa')) {
            $conn->execute('ALTER TABLE ' . $admin . ' ADD COLUMN s_2fa TEXT NULL AFTER s_secret');
        }
    }
};
