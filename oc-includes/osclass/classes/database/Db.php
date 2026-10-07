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

use InvalidArgumentException;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Class Db
 *
 * Transaction helpers operating on the singleton mysqli connection managed by
 * ConnectionManager. Supports flat begin/commit/rollBack as well as nested
 * transactions via SAVEPOINTs, so an inner transaction() call inside an outer
 * one is safe.
 *
 * Note: DDL statements (ALTER, CREATE, DROP, TRUNCATE, RENAME, ...) cause an
 * implicit COMMIT in MySQL and cannot be rolled back. These helpers must wrap
 * DML only (INSERT/UPDATE/DELETE/REPLACE).
 *
 * @package mindstellar\database
 */
class Db
{
    /**
     * Current transaction nesting depth.
     *
     * @var int
     */
    private static $depth = 0;

    /**
     * Whether the end-of-request leaked-transaction guard has been registered.
     *
     * @var bool
     */
    private static $leakGuardArmed = false;

    /**
     * Callbacks waiting for the outermost commit, keyed by the nesting level that queued them.
     *
     * @var array<int,array<int,callable>>
     */
    private static $afterCommit = array();

    /**
     * Resolve the singleton mysqli connection through the Connection wrapper, which
     * owns the sole sanctioned access to the raw handle. Keeps this class off the
     * deprecated DBConnectionClass::getOsclassDb() path.
     *
     * @return mysqli
     * @throws DbException when no database connection is available
     */
    private static function conn(): mysqli
    {
        return Connection::getInstance()->handle();
    }

    /**
     * Start a new immutable query builder for $table.
     *
     * Entry point to the fluent QueryBuilder: every clause method returns a
     * cloned builder, so the returned object can be reused and branched without
     * shared state, and terminals compile to prepared statements.
     *
     * @param string $table
     *
     * @return QueryBuilder
     */
    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }

    /**
     * Run a parameterized SELECT and return every row.
     *
     * @param array<int,mixed> $params
     *
     * @return array<int,array<string,mixed>>
     * @throws DbException
     */
    public static function select(string $sql, array $params = []): array
    {
        return Connection::getInstance()->select($sql, $params);
    }

    /**
     * Run a parameterized SELECT and return the first row, or null.
     *
     * @param array<int,mixed> $params
     *
     * @return array<string,mixed>|null
     * @throws DbException
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        return Connection::getInstance()->selectOne($sql, $params);
    }

    /**
     * Run a parameterized SELECT and return the first column of the first row.
     *
     * @param array<int,mixed> $params
     *
     * @return mixed null when there is no row
     * @throws DbException
     */
    public static function scalar(string $sql, array $params = [])
    {
        return Connection::getInstance()->scalar($sql, $params);
    }

    /**
     * Count the rows of $table, optionally under a parameterized WHERE clause.
     *
     * @param array<int,mixed> $params
     *
     * @throws DbException
     */
    public static function count(string $table, string $where = '', array $params = []): int
    {
        return (int) self::scalar('SELECT COUNT(*) FROM ' . $table . ($where !== '' ? ' WHERE ' . $where : ''), $params);
    }

    /**
     * Run a parameterized write and return the affected row count.
     *
     * @param array<int,mixed> $params
     *
     * @throws DbException
     */
    public static function execute(string $sql, array $params = []): int
    {
        return Connection::getInstance()->execute($sql, $params);
    }

    /**
     * Run a parameterized INSERT and return the new row's id.
     *
     * @param array<int,mixed> $params
     *
     * @throws DbException
     */
    public static function insertGetId(string $sql, array $params = []): int
    {
        return Connection::getInstance()->insertGetId($sql, $params);
    }

    /**
     * A row with every value a string, null kept, true and false as '1' and '0': the shape
     * the legacy query layer gave. A FLOAT column keeps its type's value, not its rendered form.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function stringifyRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v === null || is_string($v)) {
                continue;
            }
            $row[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }

        return $row;
    }

    /**
     * stringifyRow() for each row.
     *
     * @param array<int,mixed> $rows
     *
     * @return array<int,mixed>
     */
    public static function stringifyRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (is_array($row)) {
                $rows[$i] = self::stringifyRow($row);
            }
        }

        return $rows;
    }

    /**
     * Start a transaction, or a SAVEPOINT when one is already open, and increment
     * the nesting depth. This is depth-aware on purpose: a bare
     * begin_transaction() issued while a transaction is already open would cause
     * MySQL to implicitly COMMIT the outer transaction, so nesting is done with
     * savepoints instead. Pairs with commit()/rollBack(), which unwind the same
     * levels — so mixing these raw helpers with transaction() is safe.
     *
     * @return bool
     */
    public static function beginTransaction(): bool
    {
        self::armLeakGuard();

        if (self::$depth > 0) {
            if (!self::savepoint('oscsp' . self::$depth)) {
                return false;
            }
            self::$depth++;

            return true;
        }

        try {
            $result = self::conn()->begin_transaction();
        } catch (Throwable $e) {
            return false;
        }
        if ($result) {
            self::$depth++;
        }

        return $result;
    }

    /**
     * Commit the innermost level: release its SAVEPOINT when nested, or commit the
     * real transaction at the outermost level. Decrements the nesting depth.
     *
     * @return bool
     */
    public static function commit(): bool
    {
        if (self::$depth > 1) {
            $result = self::releaseSavepoint('oscsp' . (self::$depth - 1));
            // The savepoint's work now belongs to the enclosing level, and so do its callbacks.
            if (isset(self::$afterCommit[self::$depth])) {
                foreach (self::$afterCommit[self::$depth] as $fn) {
                    self::$afterCommit[self::$depth - 1][] = $fn;
                }
                unset(self::$afterCommit[self::$depth]);
            }
            self::$depth--;

            return $result;
        }

        try {
            $result = self::conn()->commit();
        } catch (Throwable $e) {
            $result = false;
        } finally {
            if (self::$depth > 0) {
                self::$depth--;
            }
        }
        $queued             = self::$afterCommit[1] ?? array();
        self::$afterCommit = array();
        if ($result) {
            self::runCallbacks($queued);
        }

        return $result;
    }

    /**
     * Roll back the innermost level: to its SAVEPOINT when nested, or the whole
     * transaction at the outermost level. Decrements the nesting depth.
     *
     * @return bool
     */
    public static function rollBack(): bool
    {
        if (self::$depth > 1) {
            $result = self::rollbackToSavepoint('oscsp' . (self::$depth - 1));
            unset(self::$afterCommit[self::$depth]);
            self::$depth--;

            return $result;
        }

        self::$afterCommit = array();
        try {
            return self::conn()->rollback();
        } catch (Throwable $e) {
            return false;
        } finally {
            if (self::$depth > 0) {
                self::$depth--;
            }
        }
    }

    /**
     * Run $fn once the outermost transaction commits, or now when none is open. It is dropped
     * if the level that queued it rolls back, so work that cannot be undone, like removing a
     * file, never outruns a write that may still be rolled back.
     *
     * @param callable $fn
     *
     * @return void
     */
    public static function afterCommit(callable $fn): void
    {
        if (self::$depth <= 0) {
            $fn();

            return;
        }
        self::$afterCommit[self::$depth][] = $fn;
    }

    /**
     * Run callbacks queued for a commit that has happened. One that throws is logged, not
     * thrown: the write is committed, so the caller must still see success.
     *
     * @param array<int,callable> $callbacks
     *
     * @return void
     */
    private static function runCallbacks(array $callbacks): void
    {
        foreach ($callbacks as $fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                error_log('Db: an after-commit callback failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Whether a transaction is currently open.
     *
     * @return bool
     */
    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Arm, once per request, a shutdown check that catches a transaction left open at request
     * end — a begin() without a matching commit()/rollBack() (an escaped exception outside the
     * transaction() wrapper, a plugin's raw osc_db_begin, or mismatched nesting). Left alone,
     * such a transaction is rolled back implicitly when the connection closes, silently
     * discarding every write since the begin and logging nothing — the exact fingerprint of the
     * "listing saved no row, no error logged" failures. Here it is instead rolled back
     * explicitly and logged loudly, so the leak is diagnosable and the connection never closes
     * mid-transaction. Registered lazily (only if a transaction is ever opened) so a request
     * that uses none pays nothing. Public and idempotent so StrictRefusals can arm this first,
     * guaranteeing its own shutdown flush is registered after (and so runs after) this rollback.
     *
     * @return void
     */
    public static function armLeakGuard(): void
    {
        if (self::$leakGuardArmed) {
            return;
        }
        self::$leakGuardArmed = true;

        register_shutdown_function(static function (): void {
            if (self::$depth <= 0) {
                return;
            }
            error_log(sprintf(
                'Db: transaction still open at request end (depth=%d) — rolling back. A begin() '
                . 'without a matching commit()/rollBack() discards every write since it; find the '
                . 'unbalanced begin (likely a raw osc_db_begin or an escaped exception).',
                self::$depth
            ));
            // Unwind every open level so the connection is not left mid-transaction.
            $guard = 0;
            while (self::$depth > 0 && $guard++ < 1024) {
                self::rollBack();
            }
        });
    }

    /**
     * Create a named savepoint within the current transaction.
     *
     * @param string $name
     *
     * @return bool
     * @throws InvalidArgumentException when the savepoint name is not [A-Za-z0-9_]+
     */
    public static function savepoint(string $name): bool
    {
        self::assertValidName($name);

        try {
            return (bool) self::conn()->query('SAVEPOINT ' . $name);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Roll back to a named savepoint.
     *
     * @param string $name
     *
     * @return bool
     * @throws InvalidArgumentException when the savepoint name is not [A-Za-z0-9_]+
     */
    public static function rollbackToSavepoint(string $name): bool
    {
        self::assertValidName($name);

        try {
            return (bool) self::conn()->query('ROLLBACK TO ' . $name);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Release a named savepoint.
     *
     * @param string $name
     *
     * @return bool
     * @throws InvalidArgumentException when the savepoint name is not [A-Za-z0-9_]+
     */
    public static function releaseSavepoint(string $name): bool
    {
        self::assertValidName($name);

        try {
            return (bool) self::conn()->query('RELEASE SAVEPOINT ' . $name);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Run $fn inside a transaction, committing on success and rolling back on
     * any Throwable. When already inside a transaction, a SAVEPOINT is used so
     * that an inner failure does not abort the outer transaction.
     *
     * DDL auto-commits in MySQL, so $fn must perform DML only.
     *
     * @param callable $fn
     *
     * @return mixed The value returned by $fn
     * @throws RuntimeException when the transaction cannot be opened
     * @throws Throwable Re-throws whatever $fn throws, after rolling back
     */
    public static function transaction(callable $fn)
    {
        // beginTransaction()/commit()/rollBack() are depth-aware (a real
        // transaction at the top level, SAVEPOINTs when nested), so this one
        // path handles both the outer and any nested call correctly. Bail loudly
        // if it fails to open: proceeding would run $fn unprotected (top level) or
        // prematurely commit the outer transaction (nested), silently losing
        // atomicity — the one guarantee this helper exists to provide.
        if (!self::beginTransaction()) {
            throw new RuntimeException('Could not begin transaction');
        }
        try {
            $result = $fn();
            self::commit();

            return $result;
        } catch (Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }

    /**
     * Validate a savepoint identifier. Savepoint names cannot be bound as query
     * parameters, so they are an injection surface and must be whitelisted.
     *
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    private static function assertValidName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid savepoint name');
        }
    }
}

/* file end: ./oc-includes/osclass/classes/database/Db.php */
