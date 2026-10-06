<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\search\query;

use mindstellar\database\DbException;

/**
 * Runs search statements through the parameterized connection and returns rows the
 * way the text protocol gave them: every value a string or null.
 */
final class SearchExecutor
{
    /**
     * @param array{0:string,1:array<int,mixed>} $statement
     *
     * @return array<int,array<string,string|null>>
     * @throws DbException
     */
    public static function rows(array $statement): array
    {
        $rows = osc_db_select($statement[0], $statement[1]);
        if ($statement[1] !== array()) {
            // A prepared statement returns a double (relevance) as a float; give it the
            // digits the text protocol does.
            foreach ($rows as $i => $row) {
                foreach ($row as $k => $v) {
                    if (is_float($v)) {
                        $rows[$i][$k] = self::floatText($v);
                    }
                }
            }
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * The total a count statement returns, 0 when it fails.
     *
     * @param array{0:string,1:array<int,mixed>} $statement
     *
     * @return int
     */
    public static function total(array $statement): int
    {
        try {
            $row = osc_db_select_one($statement[0], $statement[1]);

            return (int)($row['total'] ?? 0);
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * The shortest text that reads back as the same float, as MySQL writes a double.
     *
     * @param float $v
     *
     * @return string
     */
    private static function floatText(float $v): string
    {
        if (!is_finite($v)) {
            return (string)$v;
        }
        $text = var_export($v, true);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}
