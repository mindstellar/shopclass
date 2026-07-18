<?php
/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\migration;

use DBCommandClass;

/**
 * Contract for a `.php` migration step.
 *
 * A migration file returns an instance of an anonymous class implementing this
 * interface, e.g.:
 *
 *   return new class implements \mindstellar\migration\MigrationInterface {
 *       public function up(DBCommandClass $comm): void
 *       {
 *           $comm->query('ALTER TABLE ' . DB_TABLE_PREFIX . 't_item MODIFY s_title VARCHAR(255) NOT NULL');
 *       }
 *   };
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
     * @param DBCommandClass $comm
     *
     * @return void
     */
    public function up(DBCommandClass $comm): void;
}
