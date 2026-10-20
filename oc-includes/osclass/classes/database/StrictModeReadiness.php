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
 * Whether a site is ready for strict SQL mode.
 *
 * Strict mode refuses a date with a zero year, month or day on write, so a row holding
 * one fails the next time it is saved, and a column whose default is one fails any
 * later ALTER TABLE. A length setting larger than its column lets a value through that
 * the column then refuses. Values that were cut short in the past leave no trace, so
 * code that writes them shows up only as refused writes (StrictRefusals).
 */
class StrictModeReadiness
{
    /** Settings that cap what a column stores: setting => array(table without prefix, column). */
    public const LENGTH_SETTINGS = array(
        'title_character_length'       => array('t_item_description', 's_title'),
        'description_character_length' => array('t_item_description', 's_description'),
    );

    /** How far back refused writes are counted. */
    public const REFUSED_WINDOW = 7 * 86400;

    /**
     * Everything the readiness report shows, read once. A failed read leaves its part empty
     * and sets 'error'.
     *
     * @param string $prefix   table prefix
     * @param int    $now
     * @param bool   $scanData also count zero dates, which scans every table with a date column
     *
     * @return array<string,mixed>
     */
    public static function report(string $prefix, int $now, bool $scanData = true): array
    {
        $report = array(
            'server_mode'   => '',
            'session_mode'  => '',
            'constant'      => defined('OSC_DB_STRICT_MODE') && OSC_DB_STRICT_MODE,
            'zero_dates'    => null,
            'zero_defaults' => array(),
            'settings'      => array(),
            'log_enabled'   => osc_is_admin_log_enabled(),
            'refused'       => array(),
            'error'         => '',
        );
        try {
            $modes                  = Db::selectOne('SELECT @@GLOBAL.sql_mode AS g, @@SESSION.sql_mode AS s');
            $report['server_mode']  = (string) ($modes['g'] ?? '');
            $report['session_mode'] = (string) ($modes['s'] ?? '');
            $report['zero_defaults'] = self::zeroDefaults($prefix);
            $report['settings']      = self::settingsTooLong($prefix);
            // Refusals are only ever written to the activity log, so with it off the log holds
            // none whether or not any happened — read it as unknown, not as a clean answer.
            $report['refused'] = $report['log_enabled'] ? self::refused($prefix, $now - self::REFUSED_WINDOW) : null;
            if ($scanData) {
                $report['zero_dates'] = self::zeroDates($prefix);
            }
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    /**
     * Whether nothing in a report stands in the way of strict mode. A report whose 'refused' is
     * unknown (the activity log is off) never counts as ready.
     *
     * @param array<string,mixed> $report see report()
     *
     * @return bool
     */
    public static function ready(array $report): bool
    {
        $refused = array_key_exists('refused', $report) ? $report['refused'] : array();

        return ($report['error'] ?? '') === ''
            && ($report['zero_dates'] ?? array()) === array()
            && ($report['zero_defaults'] ?? array()) === array()
            && ($report['settings'] ?? array()) === array()
            && $refused === array();
    }

    /**
     * Whether an sql_mode refuses bad values rather than cutting them.
     *
     * @param string $mode
     *
     * @return bool
     */
    public static function isStrict(string $mode): bool
    {
        $modes = array_map('trim', explode(',', strtoupper($mode)));

        return in_array('STRICT_TRANS_TABLES', $modes, true) || in_array('STRICT_ALL_TABLES', $modes, true);
    }

    /**
     * Length settings larger than the column that stores the value.
     *
     * @param array<string,int> $values setting => stored value
     * @param array<string,int> $widths "table.column" => characters it holds
     * @param string            $prefix
     *
     * @return array<int,array{setting:string,value:int,column:string,width:int}>
     */
    public static function settingsOverColumns(array $values, array $widths, string $prefix): array
    {
        $found = array();
        foreach (self::LENGTH_SETTINGS as $setting => $target) {
            $column = $prefix . $target[0] . '.' . $target[1];
            $value  = (int) ($values[$setting] ?? 0);
            $width  = $widths[$column] ?? null;
            if ($value > 0 && $width !== null && $value > $width) {
                $found[] = array('setting' => $setting, 'value' => $value, 'column' => $column, 'width' => (int) $width);
            }
        }

        return $found;
    }

    /**
     * The stored length settings checked against their columns' widths in information_schema.
     *
     * @param string $prefix
     *
     * @return array<int,array{setting:string,value:int,column:string,width:int}>
     */
    public static function settingsTooLong(string $prefix): array
    {
        $names  = array_keys(self::LENGTH_SETTINGS);
        $marks  = implode(', ', array_fill(0, count($names), '?'));
        $values = array();
        foreach (Db::select(
            'SELECT s_name, s_value FROM ' . self::ident($prefix . 't_preference') . " WHERE s_section = 'osclass' AND s_name IN ($marks)",
            $names
        ) as $row) {
            $values[(string) $row['s_name']] = (int) $row['s_value'];
        }

        $widths = array();
        foreach (self::LENGTH_SETTINGS as $target) {
            $row = Db::selectOne(
                'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, CHARACTER_SET_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                array($prefix . $target[0], $target[1])
            );
            if ($row !== null) {
                $widths[$prefix . $target[0] . '.' . $target[1]] = self::width($row);
            }
        }

        return self::settingsOverColumns($values, $widths, $prefix);
    }

    /**
     * Writes refused by strict mode since $since, from the activity log, most recent first.
     *
     * @param string $prefix
     * @param int    $since
     *
     * @return array<int,array{column:string,kind:string,count:int,last:string}>
     */
    public static function refused(string $prefix, int $since): array
    {
        $rows = Db::select(
            'SELECT s_data, s_action, COUNT(*) AS n, MAX(dt_date) AS last FROM ' . self::ident($prefix . 't_log')
            . ' WHERE s_section = ? AND dt_date >= ? GROUP BY s_data, s_action ORDER BY last DESC LIMIT 50',
            array(StrictRefusals::SECTION, date('Y-m-d H:i:s', $since))
        );

        return array_map(static function ($row) {
            return array(
                'column' => (string) $row['s_data'],
                'kind'   => (string) $row['s_action'],
                'count'  => (int) $row['n'],
                'last'   => (string) $row['last'],
            );
        }, $rows);
    }

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
            $row = Db::selectOne('SELECT ' . implode(', ', $sums) . ' FROM ' . self::ident($table));
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
        return Db::select(
            'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_DEFAULT FROM information_schema.COLUMNS c'
            . ' JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME'
            . " WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'"
            . ' AND LEFT(c.TABLE_NAME, CHAR_LENGTH(?)) = ?'
            . " AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp') ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            array($prefix, $prefix)
        );
    }

    /**
     * The characters a text column holds: its declared length, or for TEXT types its bytes
     * divided by the widest character of its character set.
     *
     * @param array<string,mixed> $column information_schema.COLUMNS row
     *
     * @return int
     */
    private static function width(array $column): int
    {
        if (in_array(strtolower((string) $column['DATA_TYPE']), array('char', 'varchar'), true)) {
            return (int) $column['CHARACTER_MAXIMUM_LENGTH'];
        }
        $set   = strtolower((string) $column['CHARACTER_SET_NAME']);
        $bytes = $set === 'utf8mb4' ? 4 : (in_array($set, array('utf8', 'utf8mb3'), true) ? 3 : 1);

        return intdiv((int) $column['CHARACTER_OCTET_LENGTH'], $bytes);
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
