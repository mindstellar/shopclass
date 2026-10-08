<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin;

use Closure;
use mindstellar\database\Connection;
use mindstellar\database\DbException;
use mindstellar\database\SchemaDoctor;
use mindstellar\database\SchemaReconciler;
use mindstellar\database\SqlStream;
use mindstellar\database\TablePrefix;
use mindstellar\migration\MigrationRunner;
use mindstellar\upgrade\Osclass;
use Throwable;

/**
 * The work behind System info > Database: waiting updates, what Repair can fix, and the table
 * summary. Kept apart from the controller so it can be tested without an admin session.
 */
final class DatabaseTools
{
    /** Finding kinds that SchemaReconciler::repair() fixes. */
    public const REPAIRABLE = array(
        SchemaDoctor::MISSING_TABLE,
        SchemaDoctor::MISSING_COLUMN,
        SchemaDoctor::MISSING_INDEX,
        SchemaDoctor::COLUMN_TYPE,
    );

    /** Finding kinds for something a plugin or person added; Repair leaves them alone. */
    public const EXTRA = array(
        SchemaDoctor::EXTRA_COLUMN,
        SchemaDoctor::EXTRA_INDEX,
    );

    /** Finding kinds Repair does not change and a person should look at. */
    public const CLOSER_LOOK = array(
        SchemaDoctor::INDEX_COLUMNS,
        SchemaDoctor::NULLABILITY,
    );

    /**
     * The findings Repair can fix.
     *
     * @param array<int,array<string,string>> $findings SchemaDoctor::diagnose() rows
     *
     * @return array<int,array<string,string>>
     */
    public static function repairable(array $findings): array
    {
        return array_values(array_filter($findings, static function ($f) {
            return in_array($f['kind'] ?? '', self::REPAIRABLE, true);
        }));
    }

    /**
     * Whether Repair may run: no update is waiting and at least one finding is fixable.
     *
     * @param array<int,array<string,string>> $findings SchemaDoctor::diagnose() rows
     * @param string[]                        $pending  migrations not applied yet
     *
     * @return bool
     */
    public static function repairAllowed(array $findings, array $pending): bool
    {
        return $pending === array() && self::repairable($findings) !== array();
    }

    /**
     * Migrations not applied yet, in run order. Empty when there are none, or when the
     * ledger cannot be read: SchemaDoctor then reports the real error.
     *
     * @return string[]
     */
    public static function pending(): array
    {
        try {
            $runner = MigrationRunner::forCore();
            $runner->ensureLedger();

            return $runner->pending();
        } catch (Throwable $e) {
            return array();
        }
    }

    /**
     * Whether every core migration has run. False also when the ledger or the migrations
     * directory cannot be read, so the caller sends the admin to the screen that says why.
     */
    public static function upToDate(): bool
    {
        try {
            $runner = MigrationRunner::forCore();
            $runner->ensureLedger();

            return $runner->pending() === array();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * A waiting update's title: the `@title` line in the migration file's header, else
     * its file name in plain words. The file is read, never run.
     *
     * @param string $dir       the migrations directory
     * @param string $migration the migration file name
     *
     * @return string
     */
    public static function title(string $dir, string $migration): string
    {
        $head = @file_get_contents(rtrim($dir, '/') . '/' . basename($migration), false, null, 0, 8192);
        if (is_string($head) && preg_match('/@title[ \t]+([^\r\n]+)/', $head, $m)) {
            $title = trim((string) preg_replace('#\s*\*/.*$#', '', $m[1]));
            if ($title !== '') {
                return $title;
            }
        }

        return self::label($migration);
    }

    /**
     * A migration file name in plain words: "0042_job_queue.php" reads "Job queue".
     *
     * @param string $migration
     *
     * @return string
     */
    public static function label(string $migration): string
    {
        if (!preg_match('/^\d+_(.+)\.(php|sql)$/', $migration, $m)) {
            return $migration;
        }

        return ucfirst(trim(str_replace('_', ' ', $m[1])));
    }

    /**
     * Run the waiting database updates, the same path as `oc-cli.php db:upgrade`.
     *
     * @return array{error:int,message:string,applied:string[]}
     */
    public static function upgrade(): array
    {
        try {
            $result = json_decode((string) Osclass::upgradeDB(), true);
        } catch (Throwable $e) {
            $result = array('error' => 1, 'message' => $e->getMessage());
        }
        if (!is_array($result)) {
            $result = array('error' => 1, 'message' => __('Unable to upgrade Database'));
        }

        return array(
            'error'   => (int) ($result['error'] ?? 1),
            'message' => trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($result['message'] ?? '')))),
            'applied' => array_values(array_map('strval', (array) ($result['applied'] ?? array()))),
        );
    }

    /**
     * Run an SQL backup against the database one statement at a time, reading it from a
     * stream so a large file is never held in memory.
     *
     * With $replace, each of this site's tables the file creates is dropped first, so the
     * backup replaces it instead of clashing with the rows already there.
     *
     * @param Connection    $conn
     * @param resource      $handle
     * @param callable|null $each    fn(int $ran): void after each statement
     * @param bool          $replace
     *
     * @return int statements run
     * @throws \mindstellar\database\DbException on the first statement that fails
     */
    public static function restore(Connection $conn, $handle, ?callable $each = null, bool $replace = false): int
    {
        // A backup lists tables in its own order, so a key may point at a table not made yet.
        $conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $ran = 0;
            foreach (SqlStream::statements($handle) as $statement) {
                $table = $replace ? self::createdTable($statement) : null;
                if ($table !== null) {
                    $conn->execute('DROP TABLE IF EXISTS `' . $table . '`');
                }
                $conn->execute($statement);
                $ran++;
                if ($each !== null) {
                    $each($ran);
                }
            }
        } finally {
            $conn->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $ran;
    }

    /**
     * The table a CREATE TABLE statement makes, when it is one of this site's; else null.
     *
     * @param string $statement
     *
     * @return string|null
     */
    public static function createdTable(string $statement): ?string
    {
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([A-Za-z0-9_]+)`/i', $statement, $m)) {
            return null;
        }
        $prefix = defined('DB_TABLE_PREFIX') ? (string) DB_TABLE_PREFIX : '';

        return TablePrefix::owns($m[1], $prefix) ? $m[1] : null;
    }

    /**
     * Take the lock an upgrade holds, so a restore or repair cannot run beside one. Returns
     * the call that releases it, or null when an upgrade holds it now.
     *
     * @param Connection $conn
     *
     * @return Closure|null
     * @throws \mindstellar\database\DbException
     */
    public static function upgradeLock(Connection $conn): ?Closure
    {
        $lock = (new MigrationRunner($conn, MigrationRunner::coreDir()))->lockName();
        if ((int) $conn->scalar('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', array($lock)) === 1) {
            // Taking it again would release it early on MySQL before 5.7.5.
            return static function (): void {
            };
        }
        if ((int) $conn->scalar('SELECT GET_LOCK(?, 0)', array($lock)) !== 1) {
            return null;
        }

        return static function () use ($conn, $lock): void {
            try {
                $conn->scalar('SELECT RELEASE_LOCK(?)', array($lock));
            } catch (Throwable $e) {
                // The server drops the lock with the session anyway.
            }
        };
    }

    /** Oldest servers Shopclass supports. */
    public const SERVER_FLOOR = array('MySQL' => '5.7.5', 'MariaDB' => '10.2');

    /**
     * The server's product and version from its version string, and whether it meets the
     * floor. "5.5.5-10.6.12-MariaDB-log" reads as MariaDB 10.6.12.
     *
     * @param string $info mysqli server_info
     *
     * @return array{label:string,supported:bool}
     */
    public static function server(string $info): array
    {
        $info = trim($info);
        if ($info === '') {
            return array('label' => '', 'supported' => true);
        }
        $product = stripos($info, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        $clean   = (string) preg_replace('/^5\.5\.5-/', '', $info);
        if (!preg_match('/\d+\.\d+(?:\.\d+)?/', $clean, $m)) {
            return array('label' => $info, 'supported' => true);
        }

        return array(
            'label'     => $product . ' ' . $m[0],
            'supported' => version_compare($m[0], self::SERVER_FLOOR[$product], '>='),
        );
    }

    /**
     * Table count and total size of this install's tables, or null when the server
     * does not say.
     *
     * @param Connection|null $conn   this site's connection by default
     * @param string|null     $prefix the table prefix; this site's by default
     *
     * @return array{tables:int,bytes:int}|null
     */
    public static function size(?Connection $conn = null, ?string $prefix = null): ?array
    {
        $prefix ??= DB_TABLE_PREFIX;
        try {
            $row = ($conn ?? Connection::getInstance())->selectOne(
                'SELECT COUNT(*) AS n, COALESCE(SUM(data_length + index_length), 0) AS bytes'
                . ' FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE ? ESCAPE \'!\'',
                array(TablePrefix::like($prefix))
            );
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }

        return array('tables' => (int) $row['n'], 'bytes' => (int) $row['bytes']);
    }

    /**
     * Repair the schema drift the reconciler can fix, under the upgrade lock. Null when an
     * upgrade holds the lock.
     *
     * @return array{ran:array<int,string>,failed:array<int,string>}|null
     */
    public static function repair(): ?array
    {
        $conn = Connection::getInstance();
        try {
            $release = self::upgradeLock($conn);
        } catch (DbException $e) {
            $release = null;
        }
        if ($release === null) {
            return null;
        }
        try {
            return (new SchemaReconciler($conn))->repair();
        } catch (Throwable $e) {
            return array('ran' => array(), 'failed' => array($e->getMessage()));
        } finally {
            $release();
        }
    }

    /**
     * The SchemaDoctor findings, and the error that stopped them ('' when none).
     *
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    public static function findings(): array
    {
        try {
            return array((new SchemaDoctor(Connection::getInstance()))->diagnose(), '');
        } catch (Throwable $e) {
            return array(array(), $e->getMessage());
        }
    }

    /**
     * The database server's version string, or '' when it cannot be read.
     */
    public static function serverInfo(): string
    {
        try {
            return Connection::getInstance()->serverInfo();
        } catch (Throwable $e) {
            return '';
        }
    }
}
