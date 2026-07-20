<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
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
 * DBConnectionClass. Supports flat begin/commit/rollBack as well as nested
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
     * Resolve the singleton mysqli connection.
     *
     * @return mysqli
     * @throws RuntimeException when no database connection is available
     */
    private static function conn(): mysqli
    {
        $db = \DBConnectionClass::newInstance()->getOsclassDb();
        if (!$db instanceof mysqli) {
            throw new RuntimeException('No database connection available');
        }

        return $db;
    }

    /**
     * Start a new transaction and increment the nesting depth.
     *
     * @return bool
     */
    public static function beginTransaction(): bool
    {
        try {
            $result = self::conn()->begin_transaction();
        } catch (Throwable $e) {
            return false;
        }
        self::$depth++;

        return $result;
    }

    /**
     * Commit the current transaction and decrement the nesting depth.
     *
     * @return bool
     */
    public static function commit(): bool
    {
        try {
            return self::conn()->commit();
        } catch (Throwable $e) {
            return false;
        } finally {
            if (self::$depth > 0) {
                self::$depth--;
            }
        }
    }

    /**
     * Roll back the current transaction and decrement the nesting depth.
     *
     * @return bool
     */
    public static function rollBack(): bool
    {
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
     * Whether a transaction is currently open.
     *
     * @return bool
     */
    public static function inTransaction(): bool
    {
        return self::$depth > 0;
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
     * @throws Throwable Re-throws whatever $fn throws, after rolling back
     */
    public static function transaction(callable $fn)
    {
        if (!self::inTransaction()) {
            self::beginTransaction();
            try {
                $result = $fn();
                self::commit();

                return $result;
            } catch (Throwable $e) {
                self::rollBack();
                throw $e;
            }
        }

        // Each nesting level gets a distinct savepoint name; reusing a name would
        // make MySQL replace the earlier savepoint of that name and corrupt
        // deeper nesting. Bump depth only after the savepoint is established.
        $name = 'oscsp' . self::$depth;
        self::savepoint($name);
        self::$depth++;
        try {
            $result = $fn();
            self::releaseSavepoint($name);
            self::$depth--;

            return $result;
        } catch (Throwable $e) {
            self::rollbackToSavepoint($name);
            self::$depth--;
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
