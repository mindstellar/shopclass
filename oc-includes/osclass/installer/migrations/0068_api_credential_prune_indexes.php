<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;
use mindstellar\migration\SchemaProbes;

/**
 * t_api_credential: indexes on (e_kind, dt_expires) and (e_kind, dt_revoked), so the daily prune of
 * old refresh tokens finds them without a table scan. Each is added only when missing; safe to re-run.
 *
 * @title Index API refresh tokens for pruning
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
        $table = DB_TABLE_PREFIX . 't_api_credential';
        if (!$this->tableExists($conn, $table)) {
            return;
        }
        $add = [];
        foreach (['idx_kind_expires' => 'dt_expires', 'idx_kind_revoked' => 'dt_revoked'] as $index => $column) {
            if (!$this->indexExists($conn, $table, $index)) {
                $add[] = 'ADD INDEX ' . $index . ' (e_kind, ' . $column . ')';
            }
        }
        if ($add !== []) {
            $conn->execute('ALTER TABLE ' . $table . ' ' . implode(', ', $add));
        }
    }
};
