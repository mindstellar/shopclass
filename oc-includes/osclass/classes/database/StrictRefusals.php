<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

use Throwable;

/**
 * Records a write that strict SQL mode refused as one activity-log row (section "strict").
 *
 * Without strict mode the server cuts such a value silently, so stored data never shows the
 * problem; only a refused write does. The row holds the table, the column and the kind of
 * refusal, never the value or the statement.
 */
final class StrictRefusals
{
    public const SECTION = 'strict';

    /** Driver error number => kind, as stored in the log's action column. */
    public const KINDS = array(
        1406 => 'data_too_long',
        1265 => 'data_truncated',
        1366 => 'incorrect_value',
        1292 => 'bad_date',
        1048 => 'cannot_be_null',
        1364 => 'no_default',
        1264 => 'out_of_range',
    );

    /** At most this many rows per request, whatever fails. */
    private const PER_REQUEST = 20;

    /** @var array<string,bool> keys already recorded this request */
    private static $seen = array();

    /** @var array<int,array{kind:string,column:string}> */
    private static $queue = array();

    /** @var bool true while a record is being written, so its own failure is not recorded */
    private static $busy = false;

    /** @var bool */
    private static $armed = false;

    /**
     * Note a failed statement. Does nothing unless the error is one strict mode raises and the
     * session is strict. Never throws.
     *
     * @param \mysqli|null $conn    the connection the statement ran on, to read its sql_mode
     * @param int          $errno
     * @param string       $message the driver's message, parsed for the column name only
     * @param string       $sql     parsed for the target table only
     *
     * @return void
     */
    public static function record(?\mysqli $conn, int $errno, string $message, string $sql): void
    {
        if (self::$busy || !isset(self::KINDS[$errno]) || count(self::$seen) >= self::PER_REQUEST) {
            return;
        }
        $refusal = self::parse($errno, $message, $sql);
        $key     = $refusal['kind'] . ' ' . $refusal['column'];
        if (isset(self::$seen[$key])) {
            return;
        }
        self::$busy = true;
        try {
            // 1048 is also raised with strict mode off, so the session decides for every kind.
            if ($conn !== null && !self::sessionIsStrict($conn)) {
                return;
            }
            self::$seen[$key] = true;
            self::$queue[]    = $refusal;
        } catch (Throwable $e) {
            return;
        } finally {
            self::$busy = false;
        }

        // Inside a transaction the row would be lost with its rollback, so it waits for the end.
        if (Db::inTransaction()) {
            if (!self::$armed) {
                self::$armed = true;
                register_shutdown_function(array(self::class, 'flush'));
            }

            return;
        }
        self::flush();
    }

    /**
     * Write the waiting records to the activity log.
     *
     * @return void
     */
    public static function flush(): void
    {
        if (self::$busy || self::$queue === array() || !class_exists('\Log')) {
            return;
        }
        self::$busy = true;
        try {
            while (self::$queue !== array()) {
                $refusal = array_shift(self::$queue);
                \Log::newInstance()->insertLog(self::SECTION, $refusal['kind'], 0, $refusal['column'], 'system', 0);
            }
        } catch (Throwable $e) {
            self::$queue = array();
        } finally {
            self::$busy = false;
        }
    }

    /**
     * The kind of refusal and "table.column" it hit, as far as they can be told. The column
     * comes from the message and the table from the statement, or from the message when it
     * names one; either is left out when unknown.
     *
     * @param int    $errno
     * @param string $message
     * @param string $sql
     *
     * @return array{kind:string,column:string}
     */
    public static function parse(int $errno, string $message, string $sql): array
    {
        $table  = self::targetTable($sql);
        $column = '';
        $names  = array();

        $at = strrpos($message, ' for column ');
        if ($at !== false && preg_match('/^ for column ((?:[`\'][^`\']*[`\']\.?)+) at row \d+/', substr($message, $at), $m)) {
            preg_match_all('/[`\']([^`\']*)[`\']/', $m[1], $parts);
            $names = $parts[1];
        } elseif (preg_match('/^(?:Column|Field) [`\']([^`\']+)[`\'] (?:cannot be null|doesn\'t have a default value)/', $message, $m)) {
            $names = array($m[1]);
        }
        if ($names !== array()) {
            $column = (string) array_pop($names);
            // MariaDB names the column as `db`.`table`.`column`.
            if (count($names) >= 1) {
                $table = (string) array_pop($names);
            }
        }

        $valid  = static function (string $name): bool {
            return (bool) preg_match('/^[A-Za-z0-9_$]{1,64}$/', $name);
        };
        $table  = $valid($table) ? $table : '';
        $column = $valid($column) ? $column : '';

        return array(
            'kind'   => self::KINDS[$errno] ?? 'unknown',
            'column' => $table !== '' && $column !== '' ? $table . '.' . $column : $table . $column,
        );
    }

    /**
     * The refusals recorded this request, as "kind table.column".
     *
     * @return array<int,string>
     */
    public static function recorded(): array
    {
        return array_keys(self::$seen);
    }

    /**
     * Forget what this request recorded. For tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$seen  = array();
        self::$queue = array();
        self::$busy  = false;
    }

    /**
     * The table an INSERT, REPLACE or UPDATE writes to; '' for anything else.
     *
     * @param string $sql
     *
     * @return string
     */
    private static function targetTable(string $sql): string
    {
        $pattern = '/^\s*(?:(?:INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*(?:\s+INTO)?'
            . '|UPDATE(?:\s+(?:LOW_PRIORITY|IGNORE))*)\s+(?:`?[A-Za-z0-9_$]+`?\.)?`?([A-Za-z0-9_$]+)`?/i';

        return preg_match($pattern, $sql, $m) ? $m[1] : '';
    }

    /**
     * @param \mysqli $conn
     *
     * @return bool
     */
    private static function sessionIsStrict(\mysqli $conn): bool
    {
        $result = $conn->query('SELECT @@SESSION.sql_mode');
        $mode   = $result instanceof \mysqli_result ? (string) ($result->fetch_row()[0] ?? '') : '';

        return StrictModeReadiness::isStrict($mode);
    }
}
