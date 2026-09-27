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
 * Add t_job_queue.s_unique, a waiting job's de-duplication key, unique per job type.
 * Existing rows get NULL, which never clashes. Guarded, so a re-run is safe.
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
        $table = DB_TABLE_PREFIX . 't_job_queue';
        if (!$this->columnExists($conn, $table, 's_unique')) {
            $conn->execute('ALTER TABLE ' . $table . ' ADD COLUMN s_unique VARCHAR(100) NULL AFTER s_type');
        }
        if (!$this->indexExists($conn, $table, 'uk_type_unique')) {
            $conn->execute('ALTER TABLE ' . $table . ' ADD UNIQUE KEY uk_type_unique (s_type, s_unique)');
        }
    }
};
