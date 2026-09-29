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

use mindstellar\database\Connection;
use mindstellar\database\SchemaDoctor;
use mindstellar\database\SqlStream;
use mindstellar\migration\MigrationRunner;
use mindstellar\upgrade\Osclass;
use Throwable;

/**
 * The work behind Tools > Database: waiting updates, what Repair can fix, and the table
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

    /** Old Tools actions whose screen is now a part of another page, with where they land. */
    public const MOVED = array(
        'backup'      => 'database#backup',
        'backup_post' => 'database#backup',
        'import'      => 'database#restore',
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
     * Where an old Tools URL now points, as a query string for the admin index, or null
     * when the action still has its own screen.
     *
     * @param string $action
     *
     * @return string|null
     */
    public static function movedTo(string $action): ?string
    {
        if (!isset(self::MOVED[$action])) {
            return null;
        }
        list($target, $fragment) = explode('#', self::MOVED[$action]) + array(1 => '');

        return '?page=tools&action=' . $target . ($fragment !== '' ? '#' . $fragment : '');
    }

    /**
     * Migrations not applied yet, in run order. Empty when there are none, or when the
     * ledger cannot be read: SchemaDoctor then reports the real error.
     *
     * @param Connection $conn
     * @param string     $dir the migrations directory
     *
     * @return string[]
     */
    public static function pending(Connection $conn, string $dir): array
    {
        try {
            $runner = new MigrationRunner($conn, $dir);
            $runner->ensureLedger();

            return $runner->pending();
        } catch (Throwable $e) {
            return array();
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
     * @param Connection $conn
     * @param resource   $handle
     *
     * @return int statements run
     * @throws \mindstellar\database\DbException on the first statement that fails
     */
    public static function restore(Connection $conn, $handle): int
    {
        // A backup lists tables in its own order, so a key may point at a table not made yet.
        $conn->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $ran = 0;
            foreach (SqlStream::statements($handle) as $statement) {
                $conn->execute($statement);
                $ran++;
            }
        } finally {
            $conn->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $ran;
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
     * A byte count in words: "63.9 MB".
     *
     * @param int $bytes
     *
     * @return string
     */
    public static function bytes(int $bytes): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i     = $bytes > 0 ? (int) min(floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }

    /**
     * Table count and total size of this install's tables, or null when the server
     * does not say.
     *
     * @param Connection $conn
     * @param string     $prefix the table prefix
     *
     * @return array{tables:int,bytes:int}|null
     */
    public static function size(Connection $conn, string $prefix): ?array
    {
        try {
            $row = $conn->selectOne(
                'SELECT COUNT(*) AS n, COALESCE(SUM(data_length + index_length), 0) AS bytes'
                . ' FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE ?',
                array(addcslashes($prefix, '\\%_') . '%')
            );
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }

        return array('tables' => (int) $row['n'], 'bytes' => (int) $row['bytes']);
    }
}
