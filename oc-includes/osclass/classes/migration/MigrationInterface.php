<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
