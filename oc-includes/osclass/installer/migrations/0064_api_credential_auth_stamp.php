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
 * t_api_credential.i_auth_stamp: the owner's sign-out stamp when a key or refresh token was
 * issued, so a raised stamp ends it even if no sign-out action ran. Nullable and added last,
 * so no row is rewritten; safe to re-run.
 *
 * @title Bind API credentials to the sign-out stamp
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
        $credential = DB_TABLE_PREFIX . 't_api_credential';
        if ($this->tableExists($conn, $credential) && !$this->columnExists($conn, $credential, 'i_auth_stamp')) {
            $conn->execute('ALTER TABLE ' . $credential . ' ADD COLUMN i_auth_stamp INT UNSIGNED NULL');
        }
    }
};
