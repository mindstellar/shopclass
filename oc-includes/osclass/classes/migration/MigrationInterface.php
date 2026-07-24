<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\migration;

use mindstellar\database\Connection;

/**
 * Contract for a `.php` migration step.
 *
 * A migration file returns an instance of an anonymous class implementing this
 * interface, e.g.:
 *
 *   return new class implements \mindstellar\migration\MigrationInterface {
 *       public function up(\mindstellar\database\Connection $conn): void
 *       {
 *           $conn->execute('ALTER TABLE ' . DB_TABLE_PREFIX . 't_item MODIFY s_title VARCHAR(255) NOT NULL');
 *       }
 *   };
 *
 * Values belong in bound parameters rather than in the statement text:
 *
 *   $conn->execute('UPDATE t SET a = ? WHERE b = ?', array($a, $b));
 *   foreach ($conn->select('SELECT x FROM t WHERE y = ?', array($y)) as $row) { ... }
 *
 * Connection throws DbException on a failed statement, so a migration that does
 * nothing special halts the run by default and is not recorded.
 *
 * Migrations are forward-only: there is no down()/rollback. Keep each migration
 * to a single logical change so a mid-way failure leaves the least partial state
 * (MySQL auto-commits DDL — a multi-statement step can partially apply).
 */
interface MigrationInterface
{
    /**
     * Apply the migration. Throw on failure so the runner halts and does not
     * record the step as applied.
     *
     * @param Connection $conn
     *
     * @return void
     */
    public function up(Connection $conn): void;
}
