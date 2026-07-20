<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Database
 *
 * Thin global wrappers around mindstellar\database\Db for transaction control
 * and mindstellar\database\Connection for injection-safe parameterized queries.
 *
 * @package    Shopclass
 * @subpackage Helpers
 */

if (!function_exists('osc_db_select')) {
    /**
     * Run a parameterized SELECT and return every row as an associative array.
     *
     * Values in $params are bound as positional '?' parameters, so they can
     * never be interpreted as SQL.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return array List of rows (empty when none match)
     */
    function osc_db_select(string $sql, array $params = []): array
    {
        return \mindstellar\database\Connection::instance()->select($sql, $params);
    }
}

if (!function_exists('osc_db_select_one')) {
    /**
     * Run a parameterized SELECT and return the first row, or null.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return array|null
     */
    function osc_db_select_one(string $sql, array $params = []): ?array
    {
        return \mindstellar\database\Connection::instance()->selectOne($sql, $params);
    }
}

if (!function_exists('osc_db_scalar')) {
    /**
     * Run a parameterized SELECT and return the first column of the first row.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return mixed Null when there are no rows
     */
    function osc_db_scalar(string $sql, array $params = [])
    {
        return \mindstellar\database\Connection::instance()->scalar($sql, $params);
    }
}

if (!function_exists('osc_db_execute')) {
    /**
     * Run a parameterized INSERT/UPDATE/DELETE and return affected rows.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return int
     */
    function osc_db_execute(string $sql, array $params = []): int
    {
        return \mindstellar\database\Connection::instance()->execute($sql, $params);
    }
}

if (!function_exists('osc_db_insert_id')) {
    /**
     * Run a parameterized INSERT and return the generated AUTO_INCREMENT id.
     *
     * @param string $sql
     * @param array  $params
     *
     * @return int
     */
    function osc_db_insert_id(string $sql, array $params = []): int
    {
        return \mindstellar\database\Connection::instance()->insertGetId($sql, $params);
    }
}

if (!function_exists('osc_db_transaction')) {
    /**
     * Run $fn inside a database transaction (or a savepoint when nested),
     * committing on success and rolling back on any Throwable.
     *
     * @param callable $fn
     *
     * @return mixed The value returned by $fn
     */
    function osc_db_transaction(callable $fn)
    {
        return \mindstellar\database\Db::transaction($fn);
    }
}

if (!function_exists('osc_db_begin')) {
    /**
     * Begin a database transaction.
     *
     * @return bool
     */
    function osc_db_begin(): bool
    {
        return \mindstellar\database\Db::beginTransaction();
    }
}

if (!function_exists('osc_db_commit')) {
    /**
     * Commit the current database transaction.
     *
     * @return bool
     */
    function osc_db_commit(): bool
    {
        return \mindstellar\database\Db::commit();
    }
}

if (!function_exists('osc_db_rollback')) {
    /**
     * Roll back the current database transaction.
     *
     * @return bool
     */
    function osc_db_rollback(): bool
    {
        return \mindstellar\database\Db::rollBack();
    }
}

/* file end: ./oc-includes/osclass/helpers/hDatabase.php */
