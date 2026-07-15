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
use RuntimeException;
use Throwable;

/**
 * Forward-only migration runner backed by a ledger table (t_migration).
 *
 * Complements DBCommandClass::updateDB(), which diffs struct.sql against the live schema and
 * can add tables/columns/indexes/foreign keys and even apply column type and default changes
 * (CHANGE COLUMN / ALTER COLUMN). What it cannot do is DROP a column/index/table, rename, or
 * transform/backfill data — anything of that kind is authored as an ordered, immutable
 * migration file under installer/migrations/ and applied here exactly once, in order,
 * recorded on success.
 *
 * Migration files are named NNNN_description.sql or NNNN_description.php (zero-padded prefix
 * so string order == numeric order). `.sql` files run their statements verbatim; `.php`
 * files return an object with an up(DBCommandClass) method (see MigrationInterface).
 */
class MigrationRunner
{
    private DBCommandClass $comm;
    private string $dir;
    private string $table;

    /**
     * @param DBCommandClass $comm         command object bound to the Osclass DB
     * @param string         $migrationsDir absolute path to the migrations directory
     */
    public function __construct(DBCommandClass $comm, string $migrationsDir)
    {
        $this->comm  = $comm;
        $this->dir   = rtrim($migrationsDir, '/\\');
        $this->table = DB_TABLE_PREFIX . 't_migration';
    }

    /**
     * Create the ledger table if it does not exist. Lets installs upgrading from a
     * pre-migration version self-bootstrap on their first run.
     *
     * @return void
     */
    public function ensureLedger(): void
    {
        $this->comm->query(
            'CREATE TABLE IF NOT EXISTS ' . $this->table . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_migration VARCHAR(255) NOT NULL,'
            . ' dt_applied DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uq_s_migration (s_migration)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'UTF8' COLLATE 'UTF8_GENERAL_CI'"
        );
    }

    /**
     * Names of migrations already recorded in the ledger.
     *
     * @return string[]
     */
    public function applied(): array
    {
        $result = $this->comm->query('SELECT s_migration FROM ' . $this->table);
        if (!is_object($result)) {
            return array();
        }

        $names = array();
        foreach ($result->result('array') as $row) {
            $names[] = $row['s_migration'];
        }

        return $names;
    }

    /**
     * Migration filenames present on disk but not yet applied, in run order.
     *
     * @return string[]
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->discover(),
            static function ($name) use ($applied) {
                return !in_array($name, $applied, true);
            }
        ));
    }

    /**
     * Apply every pending migration in order, recording each on success. Fail-fast:
     * the first migration that throws halts the run and is NOT recorded, so the next
     * upgrade retries from there.
     *
     * @return array{ok:bool,applied:string[],failed:?string,error:?string}
     */
    public function run(): array
    {
        $applied = array();
        foreach ($this->pending() as $name) {
            try {
                $this->apply($name);
            } catch (Throwable $e) {
                return array(
                    'ok'      => false,
                    'applied' => $applied,
                    'failed'  => $name,
                    'error'   => $e->getMessage(),
                );
            }
            $this->record($name);
            $applied[] = $name;
        }

        return array('ok' => true, 'applied' => $applied, 'failed' => null, 'error' => null);
    }

    /**
     * Record all on-disk migrations as applied WITHOUT running them. Used by a fresh
     * install, whose schema is already at the target state — replaying historical
     * backfills against it would be wrong.
     *
     * @return void
     */
    public function baseline(): void
    {
        $applied = $this->applied();
        foreach ($this->discover() as $name) {
            if (!in_array($name, $applied, true)) {
                $this->record($name);
            }
        }
    }

    /**
     * All migration filenames on disk, sorted into run order.
     *
     * @return string[]
     */
    private function discover(): array
    {
        if (!is_dir($this->dir)) {
            return array();
        }

        $files = array();
        foreach (scandir($this->dir) as $entry) {
            if ($entry === 'index.php') {
                continue;
            }
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext === 'sql' || $ext === 'php') {
                $files[] = $entry;
            }
        }
        // Zero-padded numeric prefixes mean string order == intended run order.
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Dispatch a single migration by file extension.
     *
     * @param string $name migration filename
     *
     * @return void
     */
    private function apply($name): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'sql') {
            $this->applySql($path);
        } else {
            $this->applyPhp($path);
        }
    }

    /**
     * @param string $path
     *
     * @return void
     */
    private function applySql($path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration file: ' . $path);
        }

        $sql = str_replace('/*TABLE_PREFIX*/', DB_TABLE_PREFIX, $sql);
        foreach ($this->splitStatements($sql) as $statement) {
            if ($this->comm->query($statement) === false) {
                throw new RuntimeException('Migration statement failed: ' . $statement);
            }
        }
    }

    /**
     * @param string $path
     *
     * @return void
     */
    private function applyPhp($path): void
    {
        $migration = require $path;
        if (!is_object($migration) || !method_exists($migration, 'up')) {
            throw new RuntimeException('Migration must return an object with an up() method: ' . $path);
        }

        $migration->up($this->comm);
    }

    /**
     * Naive `;` split — adequate for the one-logical-change-per-migration convention.
     * Migrations needing statements that embed `;` should use a `.php` migration instead.
     *
     * @param string $sql
     *
     * @return string[]
     */
    private function splitStatements($sql): array
    {
        $statements = array();
        foreach (explode(';', $sql) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }

    /**
     * @param string $name
     *
     * @return void
     */
    private function record($name): void
    {
        $this->comm->query(
            'INSERT INTO ' . $this->table . ' (s_migration, dt_applied) VALUES ('
            . $this->comm->escape($name) . ', NOW())'
        );
    }
}
