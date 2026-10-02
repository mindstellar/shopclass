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
 * The table prefix: the token SQL files carry in its place, and which tables belong to
 * the install that uses it.
 */
final class TablePrefix
{
    /** Stands for the table prefix in an SQL file, so it loads onto any prefix. */
    public const TOKEN = '/*TABLE_PREFIX*/';

    /**
     * Whether a table belongs to the install with this prefix. Its tables are named
     * prefix + "t_", so another install on a longer prefix (oc_ and oc_shop2_) never matches.
     *
     * @param string $table
     * @param string $prefix
     *
     * @return bool
     */
    public static function owns(string $table, string $prefix): bool
    {
        return strpos($table, $prefix . 't_') === 0;
    }

    /**
     * A LIKE pattern for the tables owns() accepts.
     *
     * @param string $prefix
     *
     * @return string
     */
    public static function like(string $prefix): string
    {
        return str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $prefix) . 't!_%';
    }

    /**
     * Put the prefix in place of the token.
     *
     * @param string $sql
     * @param string $prefix
     *
     * @return string
     */
    public static function expand(string $sql, string $prefix): string
    {
        return str_replace(self::TOKEN, $prefix, $sql);
    }
}
