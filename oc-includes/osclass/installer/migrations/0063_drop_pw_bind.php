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
 * For development installs that ran an earlier 0060 or 0061: drop t_api_credential.s_pw_bind,
 * which never shipped in a release; API credentials now end with the account's sign-out
 * stamp. A no-op everywhere else; safe to re-run.
 *
 * @title Drop the API password binding
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
        if ($this->tableExists($conn, $credential) && $this->columnExists($conn, $credential, 's_pw_bind')) {
            $conn->execute('ALTER TABLE ' . $credential . ' DROP COLUMN s_pw_bind');
        }
    }
};
