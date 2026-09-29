<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\backup;

use Dump;
use RuntimeException;

/**
 * Writes this site's tables to an SQL file for a backup, with the prefix token in place
 * of the table prefix so the file restores onto any prefix.
 */
final class DatabaseDump
{
    /** Core tables first, parents before children, so a restore meets no missing parent. */
    public const ORDER = array(
        't_locale',
        't_country',
        't_currency',
        't_region',
        't_city',
        't_city_area',
        't_widget',
        't_admin',
        't_user',
        't_user_description',
        't_category',
        't_category_description',
        't_category_stats',
        't_item',
        't_item_description',
        't_item_location',
        't_item_stats',
        't_item_stats_daily',
        't_item_resource',
        't_item_comment',
        't_preference',
        't_pages',
        't_pages_description',
        't_plugin_category',
        't_cron',
        't_alerts',
        't_meta_fields',
        't_meta_categories',
        't_item_meta',
    );

    /**
     * Table names in dump order: the core order first, the rest as given.
     *
     * @param string[] $tables
     * @param string   $prefix
     *
     * @return string[]
     */
    public static function order(array $tables, string $prefix): array
    {
        $rest    = array_combine($tables, $tables);
        $ordered = array();
        foreach (self::ORDER as $table) {
            if (isset($rest[$prefix . $table])) {
                $ordered[] = $prefix . $table;
                unset($rest[$prefix . $table]);
            }
        }

        return array_merge($ordered, array_values($rest));
    }

    /**
     * This site's tables, the ones carrying its prefix.
     *
     * @return string[]
     */
    public static function tables(): array
    {
        $tables = array();
        foreach (Dump::newInstance()->showTables() as $row) {
            $name = (string) current($row);
            if (DB_TABLE_PREFIX === '' || strpos($name, DB_TABLE_PREFIX) === 0) {
                $tables[] = $name;
            }
        }

        return self::order($tables, DB_TABLE_PREFIX);
    }

    /**
     * Dump every table of this site into $file, which must exist and be writable.
     *
     * @param string        $file
     * @param callable|null $each fn(int $done, int $total, string $table): void, before each table
     *
     * @return array{tables:int,bytes:int}
     * @throws RuntimeException when there is nothing to dump or the file cannot be written
     */
    public static function write(string $file, ?callable $each = null): array
    {
        $tables = self::tables();
        if ($tables === array()) {
            throw new RuntimeException('There are no tables to back up');
        }
        if (@file_put_contents($file, '/* Shopclass database backup ' . date('c') . " */\n", FILE_APPEND) === false) {
            throw new RuntimeException('Could not write the database backup');
        }
        $dump = Dump::newInstance();
        foreach ($tables as $i => $table) {
            if ($each !== null) {
                $each($i, count($tables), $table);
            }
            if (!$dump->table_structure($file, $table, true) || !$dump->table_data($file, $table, true)) {
                throw new RuntimeException('Could not write the database backup');
            }
        }
        clearstatcache(true, $file);

        return array('tables' => count($tables), 'bytes' => (int) filesize($file));
    }
}
