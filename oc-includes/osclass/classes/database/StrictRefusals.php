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

    /** Driver error number => kind, as stored in the log's action column. 1292 is refined
     *  to 'bad_date' by kindFor() when the message names a date/time value; it also covers
     *  plain numeric truncation, which stays 'incorrect_value'. */
    public const KINDS = array(
        1406 => 'data_too_long',
        1265 => 'data_truncated',
        1366 => 'incorrect_value',
        1292 => 'incorrect_value',
        1048 => 'cannot_be_null',
        1364 => 'no_default',
        1264 => 'out_of_range',
    );

    /** At most this many rows per request, whatever fails. */
    private const PER_REQUEST = 20;

    /** @var array<string,bool> keys already queued this request */
    private static $seen = array();

    /** @var array<int,array{kind:string,column:string}> */
    private static $queue = array();

    /** @var bool true while a record is queued or flushed, so its own failure is not recorded */
    private static $busy = false;

    /** @var bool whether the shutdown flush is registered */
    private static $armed = false;

    /** @var \mysqli|null the connection to check sql_mode on at flush time */
    private static $conn;

    /**
     * Note a failed statement, queuing (kind, table.column) in memory with no database call.
     * Never throws.
     *
     * @param \mysqli|null $conn    the connection the statement ran on, read for sql_mode at flush
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
        self::$seen[$key] = true;
        self::$queue[]    = $refusal;
        if (self::$conn === null) {
            self::$conn = $conn;
        }

        if (!self::$armed) {
            self::$armed = true;
            // Db's own leak guard rolls back a transaction left open at request end; arming
            // it here (idempotent if already armed) guarantees it is registered, and so runs,
            // before the flush registered next.
            Db::armLeakGuard();
            register_shutdown_function(array(self::class, 'flush'));
        }
    }

    /**
     * Write the waiting records to the activity log. Checks the session sql_mode once, here,
     * and writes nothing when it is not strict.
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
            if (!self::sessionIsStrict()) {
                self::$queue = array();

                return;
            }
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
     * name is read only from the very end of the message — never wherever " for column " first
     * appears — so a refused value that itself contains that text is not mistaken for it.
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

        if (!str_starts_with($message, 'Truncated incorrect')) {
            if (preg_match('/ for column ((?:[`\'][^`\']*[`\']\.?)+) at row \d+$/', $message, $m)) {
                preg_match_all('/[`\']([^`\']*)[`\']/', $m[1], $parts);
                $names = $parts[1];
            } elseif (preg_match('/^(?:Column|Field) [`\']([^`\']+)[`\'] (?:cannot be null|doesn\'t have a default value)/', $message, $m)) {
                $names = array($m[1]);
            }
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
            'kind'   => self::kindFor($errno, $message),
            'column' => $table !== '' && $column !== '' ? $table . '.' . $column : $table . $column,
        );
    }

    /**
     * The refusals queued this request, as "kind table.column".
     *
     * @return array<int,string>
     */
    public static function recorded(): array
    {
        return array_keys(self::$seen);
    }

    /**
     * Forget what this request queued. For tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$seen  = array();
        self::$queue = array();
        self::$busy  = false;
        self::$armed = false;
        self::$conn  = null;
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
     * The kind label for an error number, refining 1292 to 'bad_date' only when the message
     * names a date/time/timestamp value — 1292 also covers plain numeric truncation.
     *
     * @param int    $errno
     * @param string $message
     *
     * @return string
     */
    private static function kindFor(int $errno, string $message): string
    {
        if ($errno === 1292 && preg_match('/\b(?:date|datetime|timestamp|time)\b/i', $message)) {
            return 'bad_date';
        }

        return self::KINDS[$errno] ?? 'unknown';
    }

    /**
     * Whether the session on the connection last seen by record() is strict. False (and thus
     * nothing written) when that connection is gone or the check itself fails.
     *
     * @return bool
     */
    private static function sessionIsStrict(): bool
    {
        $conn = self::$conn;
        if (!$conn instanceof \mysqli) {
            return false;
        }

        try {
            $result = $conn->query('SELECT @@SESSION.sql_mode');
        } catch (Throwable $e) {
            return false;
        }
        $mode = $result instanceof \mysqli_result ? (string) ($result->fetch_row()[0] ?? '') : '';

        return StrictModeReadiness::isStrict($mode);
    }
}
