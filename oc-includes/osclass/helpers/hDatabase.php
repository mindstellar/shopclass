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
 * Thin global wrappers around mindstellar\database\Db for transaction control.
 *
 * @package    Shopclass
 * @subpackage Helpers
 */

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
