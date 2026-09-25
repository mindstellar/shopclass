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

/**
 * Whether a relaxed install's stored data would survive strict SQL mode.
 *
 * Strict mode refuses a zero date ('0000-00-00') on write, so a row holding one fails
 * the next time it is saved. That is the one blocker stored data can show; code that
 * relies on silent truncation (plugins) can only be found by testing.
 */
class StrictModeReadiness
{
    /**
     * Rows holding a zero date, per column, in this site's tables.
     *
     * @param string $prefix table prefix
     *
     * @return array<string,int> "table.column" => rows, only columns that have any
     */
    public static function zeroDates(string $prefix): array
    {
        $columns = osc_db_select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?"
            . " AND DATA_TYPE IN ('date', 'datetime', 'timestamp') ORDER BY TABLE_NAME, ORDINAL_POSITION",
            array(str_replace(array('\\', '_', '%'), array('\\\\', '\\_', '\\%'), $prefix) . '%')
        );

        $found = array();
        foreach ($columns as $column) {
            $table = '`' . str_replace('`', '``', (string)$column['TABLE_NAME']) . '`';
            $name  = '`' . str_replace('`', '``', (string)$column['COLUMN_NAME']) . '`';
            // Compared as text, so the check itself runs under any sql_mode.
            $rows = (int)osc_db_scalar("SELECT COUNT(*) FROM $table WHERE CAST($name AS CHAR) LIKE '0000-00-00%'");
            if ($rows > 0) {
                $found[$column['TABLE_NAME'] . '.' . $column['COLUMN_NAME']] = $rows;
            }
        }

        return $found;
    }
}
