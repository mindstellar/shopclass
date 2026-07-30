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

if (!function_exists('osc_db_table')) {
    /**
     * Start an immutable fluent query builder for $table.
     *
     * Every clause method returns a cloned builder, so the returned object can
     * be reused and branched safely; terminals compile to prepared statements
     * and run through the injection-safe Connection API.
     *
     * @param string $table
     *
     * @return \mindstellar\database\QueryBuilder
     */
    function osc_db_table(string $table): \mindstellar\database\QueryBuilder
    {
        return \mindstellar\database\Db::table($table);
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

if (!function_exists('osc_db_stringify_row')) {
    /**
     * Coerce every value in a result row to a string, leaving null as null.
     *
     * The legacy query layer returns every column as a PHP string because it
     * reads results through mysqli::query(). The parameterized layer returns
     * native types for INT/FLOAT/DOUBLE columns, because a prepared statement
     * carries column metadata. A model whose body moves from one to the other
     * would therefore start handing callers ints where they have always had
     * strings, and comparisons such as `$row['b_active'] === '1'` would begin
     * failing silently across the call sites that consume these rows.
     *
     * Converted read methods pipe their rows through this so their observable
     * row shape is unchanged.
     *
     * Booleans map to '1'/'0' rather than PHP's '' for false, matching how
     * MySQL renders a boolean column through the legacy path.
     *
     * Fidelity limit: this restores the string TYPE, not the exact lexical form
     * the legacy driver produced. A FLOAT/DOUBLE column arrives already widened
     * to a PHP float, so trailing-zero formatting is lost ('1.50' becomes
     * '1.5'). DECIMAL is unaffected — the driver hands it back as a string. When
     * a caller depends on the rendered form of a floating-point column, select
     * it with an explicit CAST rather than relying on this helper.
     *
     * @param array $row
     *
     * @return array
     */
    function osc_db_stringify_row(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v === null || is_string($v)) {
                continue;
            }
            $row[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }

        return $row;
    }
}

if (!function_exists('osc_db_stringify_rows')) {
    /**
     * Apply osc_db_stringify_row() to a list of rows.
     *
     * @param array $rows
     *
     * @return array
     */
    function osc_db_stringify_rows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (is_array($row)) {
                $rows[$i] = osc_db_stringify_row($row);
            }
        }

        return $rows;
    }
}

/* file end: ./oc-includes/osclass/helpers/hDatabase.php */
