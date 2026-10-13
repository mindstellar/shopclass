<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\migration;

use mindstellar\database\Connection;
use mindstellar\database\SqlScript;
use RuntimeException;
use Throwable;

/**
 * Forward-only migration runner backed by a ledger table (t_migration).
 *
 * Complements the schema reconcile in the upgrader, which diffs struct.sql against the live schema and
 * can add tables/columns/indexes/foreign keys and even apply column type and default changes
 * (CHANGE COLUMN / ALTER COLUMN). What it cannot do is DROP a column/index/table, rename, or
 * transform/backfill data — anything of that kind is authored as an ordered, immutable
 * migration file under installer/migrations/ and applied here exactly once, in order,
 * recorded on success.
 *
 * Migration files are named NNNN_description.sql or NNNN_description.php (zero-padded prefix
 * so string order == numeric order). `.sql` files run their statements verbatim; `.php`
 * files return an object with an up(Connection) method (see MigrationInterface).
 *
 * A plugin's runner (forPlugin()) shares the ledger: it records each file as `<folder>/<file>`,
 * so its names never meet core's or another plugin's.
 */
class MigrationRunner
{
    /** Seconds to wait for another run to finish before giving up. Tests set OSC_LOCK_WAIT to 0. */
    private const LOCK_WAIT = 5;

    private Connection $conn;
    private string $dir;
    private string $table;
    /** Empty for core; a plugin's folder name otherwise. */
    private string $scope;

    /**
     * @param Connection $conn          connection bound to the database being migrated
     * @param string     $migrationsDir absolute path to the migrations directory
     * @param string     $scope         ledger prefix; empty for core, a plugin's folder name otherwise
     */
    public function __construct(Connection $conn, string $migrationsDir, string $scope = '')
    {
        if ($scope !== '' && !preg_match('/^[A-Za-z0-9_-]+\z/', $scope)) {
            throw new \InvalidArgumentException('Invalid migration scope: ' . $scope);
        }
        $this->conn  = $conn;
        $this->dir   = rtrim($migrationsDir, '/\\');
        $this->table = DB_TABLE_PREFIX . 't_migration';
        $this->scope = $scope;
    }

    /**
     * A runner for core's migrations on this site's connection.
     *
     * @return self
     */
    public static function forCore(): self
    {
        return new self(Connection::getInstance(), self::coreDir());
    }

    /**
     * A runner for one plugin's migrations, kept in `<plugin folder>/migrations/`.
     *
     * @param string $folder the plugin's folder name, e.g. `manticore-search`
     *
     * @return self
     */
    public static function forPlugin(string $folder): self
    {
        return new self(Connection::getInstance(), PLUGINS_PATH . $folder . '/migrations', $folder);
    }

    /**
     * The core migrations directory.
     *
     * @return string
     */
    public static function coreDir(): string
    {
        return dirname(__DIR__, 2) . '/installer/migrations';
    }

    /**
     * Create the ledger table if it does not exist. Lets installs upgrading from a
     * pre-migration version self-bootstrap on their first run.
     *
     * @return void
     * @throws \mindstellar\database\DbException
     */
    public function ensureLedger(): void
    {
        $this->conn->execute(
            'CREATE TABLE IF NOT EXISTS ' . $this->table . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_migration VARCHAR(255) NOT NULL,'
            . ' dt_applied DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY (s_migration)'
            . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
        );
    }

    /**
     * Names of migrations already recorded in the ledger.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public function applied(): array
    {
        // Core's list also holds plugin rows; pending() only checks files on disk, so they never match.
        if ($this->scope === '') {
            return array_column($this->conn->select('SELECT s_migration FROM ' . $this->table), 's_migration');
        }

        [$where, $params] = $this->scopeWhere();
        $names = array();
        foreach ($this->conn->select('SELECT s_migration FROM ' . $this->table . $where, $params) as $row) {
            $names[] = substr($row['s_migration'], strlen($this->scope) + 1);
        }

        return $names;
    }

    /**
     * Migration filenames present on disk but not yet applied, in run order.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
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
     * The run holds a named lock on the database, so two upgrade requests cannot apply the
     * same migration at once. When another run holds it, nothing is applied, `failed` is
     * null and `busy` is true.
     *
     * @return array{ok:bool,applied:string[],failed:?string,error:?string,busy?:bool}
     * @throws \mindstellar\database\DbException when the ledger itself cannot be read or written
     */
    public function run(): array
    {
        $wait = \defined('OSC_LOCK_WAIT') ? (int) \constant('OSC_LOCK_WAIT') : self::LOCK_WAIT;

        $result = $this->conn->withNamedLock($this->lockName(), $wait, function (): array {
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
        });

        return $result ?? array(
            'ok'      => false,
            'applied' => array(),
            'failed'  => null,
            'error'   => 'Another upgrade is already running.',
            'busy'    => true,
        );
    }

    /**
     * The named lock that serialises runs against one database and table prefix.
     *
     * @return string
     * @throws \mindstellar\database\DbException
     */
    public function lockName(): string
    {
        // GET_LOCK names are capped at 64 characters, so the scope is hashed.
        $scope = $this->scope === '' ? '' : '|' . $this->scope;

        return 'osc_migrate_' . md5((string) $this->conn->scalar('SELECT DATABASE()') . '|' . DB_TABLE_PREFIX . $scope);
    }

    /**
     * Record all on-disk migrations as applied WITHOUT running them. Used by a fresh
     * install, whose schema is already at the target state — replaying historical
     * backfills against it would be wrong.
     *
     * @return void
     * @throws \mindstellar\database\DbException
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
     * Drop this plugin's rows from the ledger, so a reinstall runs its migrations again.
     * Call it from the plugin's uninstall hook after dropping its tables.
     *
     * @return void
     * @throws \LogicException for core's runner
     * @throws \mindstellar\database\DbException
     */
    public function forget(): void
    {
        if ($this->scope === '') {
            throw new \LogicException('Core migrations cannot be forgotten.');
        }
        [$where, $params] = $this->scopeWhere();
        $this->conn->execute('DELETE FROM ' . $this->table . $where, $params);
    }

    /**
     * The WHERE clause for this plugin's ledger rows. A prefix compare, not LIKE: under
     * NO_BACKSLASH_ESCAPES a LIKE escape stops working and `_` matches any character.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function scopeWhere(): array
    {
        $prefix = $this->scope . '/';

        return array(' WHERE LEFT(s_migration, ?) = ?', array(strlen($prefix), $prefix));
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
     * @throws \RuntimeException on an unreadable or malformed migration file
     * @throws \mindstellar\database\DbException on a failing statement
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
     * Run every statement of a `.sql` migration in file order.
     *
     * @param string $path absolute path to the migration file
     *
     * @return void
     * @throws \RuntimeException when the file cannot be read
     * @throws \mindstellar\database\DbException on a failing statement
     */
    private function applySql($path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration file: ' . $path);
        }

        // SqlScript substitutes the schema tokens and strips block comments as
        // well as splitting, so a .sql migration is parsed exactly like the
        // schema files the installer loads.
        foreach (SqlScript::statements($sql) as $statement) {
            $this->conn->execute($statement);
        }
    }

    /**
     * Require a `.php` migration and invoke the up() method of the object it returns.
     *
     * @param string $path absolute path to the migration file
     *
     * @return void
     * @throws \RuntimeException when the file does not return an object with an up() method
     */
    private function applyPhp($path): void
    {
        $migration = require $path;
        if (!is_object($migration) || !method_exists($migration, 'up')) {
            throw new RuntimeException('Migration must return an object with an up() method: ' . $path);
        }

        $migration->up($this->conn);
    }

    /**
     * Mark one migration as applied in the ledger.
     *
     * @param string $name migration filename
     *
     * @return void
     * @throws \mindstellar\database\DbException
     */
    private function record($name): void
    {
        $this->conn->execute(
            'INSERT INTO ' . $this->table . ' (s_migration, dt_applied) VALUES (?, NOW())',
            array($this->scope === '' ? $name : $this->scope . '/' . $name)
        );
    }
}
