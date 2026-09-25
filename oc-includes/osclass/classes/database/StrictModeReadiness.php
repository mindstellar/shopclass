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
 * Whether a relaxed install's schema and data would survive strict SQL mode.
 *
 * Strict mode refuses a date with a zero year, month or day on write, so a row holding
 * one fails the next time it is saved, and a column whose default is one fails any
 * later ALTER TABLE. Code that relies on silent truncation (plugins) can only be found
 * by testing.
 */
class StrictModeReadiness
{
    /**
     * Rows holding a zero or partly zero date, per column, in this site's tables.
     *
     * @param string $prefix table prefix
     *
     * @return array<string,int> "table.column" => rows, only columns that have any
     */
    public static function zeroDates(string $prefix): array
    {
        $byTable = array();
        foreach (self::dateColumns($prefix) as $column) {
            $byTable[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
        }

        $found = array();
        foreach ($byTable as $table => $columns) {
            // One scan per table. MONTH()/DAYOFMONTH() are 0 for a zero date and for a
            // zero month or day, whatever the sql_mode.
            $sums = array();
            foreach ($columns as $i => $name) {
                $quoted = self::ident($name);
                $sums[] = "SUM(MONTH($quoted) = 0 OR DAYOFMONTH($quoted) = 0) AS c$i";
            }
            $row = osc_db_select_one('SELECT ' . implode(', ', $sums) . ' FROM ' . self::ident($table));
            foreach ($columns as $i => $name) {
                $rows = (int)($row['c' . $i] ?? 0);
                if ($rows > 0) {
                    $found[$table . '.' . $name] = $rows;
                }
            }
        }

        return $found;
    }

    /**
     * Columns whose default is a zero date, which strict mode rejects on ALTER TABLE.
     *
     * @param string $prefix table prefix
     *
     * @return array<int,string> "table.column"
     */
    public static function zeroDefaults(string $prefix): array
    {
        $found = array();
        foreach (self::dateColumns($prefix) as $column) {
            if (strpos((string)$column['COLUMN_DEFAULT'], '0000-00-00') !== false) {
                $found[] = $column['TABLE_NAME'] . '.' . $column['COLUMN_NAME'];
            }
        }

        return $found;
    }

    /**
     * Date columns of this site's base tables, views left out.
     *
     * @param string $prefix
     *
     * @return array<int,array<string,mixed>>
     */
    private static function dateColumns(string $prefix): array
    {
        // Compared with LEFT() rather than LIKE, so '_' in a prefix needs no escaping.
        return osc_db_select(
            'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_DEFAULT FROM information_schema.COLUMNS c'
            . ' JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME'
            . " WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'"
            . ' AND LEFT(c.TABLE_NAME, CHAR_LENGTH(?)) = ?'
            . " AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp') ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            array($prefix, $prefix)
        );
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
