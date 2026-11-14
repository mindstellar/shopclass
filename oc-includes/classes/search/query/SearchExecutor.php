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

use mindstellar\database\Db;
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
        $rows = Db::select($statement[0], $statement[1]);
        if ($statement[1] !== array()) {
            // A prepared statement returns a double (relevance) as a float; give it the
            // digits the text protocol does.
            foreach ($rows as $i => $row) {
                foreach ($row as $k => $v) {
                    if (\is_float($v)) {
                        $rows[$i][$k] = self::floatText($v);
                    }
                }
            }
        }

        return Db::stringifyRows($rows);
    }

    /**
     * The rows of the page. Price low to high reads the priced listings first, then fills a short
     * page from those with no price, so each read can use an index.
     *
     * @return array<int,array<string,string|null>>
     * @throws DbException
     */
    public static function page(SearchParts $parts, ?\DBCommandClass $dao): array
    {
        $ordering = $parts->ordering;
        // Read whole: a plugin's $dao clauses and a lead order (fromPrimaryKeys()) do not survive a split, and one listing by id has no order.
        if (!$ordering->splitsPrice() || $dao !== null || $parts->leadOrder !== [] || $parts->byItemId) {
            return self::rows(SearchCompiler::results($parts, $dao));
        }
        $price  = DB_TABLE_PREFIX . 't_item.i_price';
        $priced = clone $parts;
        $priced->ordering = $ordering->pricedFirst();
        $priced->addOnce($price . ' IS NOT NULL');
        $rest = clone $parts;
        $rest->addOnce($price . ' IS NULL');
        // The clones carry the one-time conditions; take them from $parts, as one read would.
        $parts->newStatement();
        $count = clone $priced;

        $rows   = self::rows(SearchCompiler::results($priced, null));
        $size   = (int)$ordering->perPage();
        $offset = max(0, (int)$ordering->offset());
        if (count($rows) >= $size) {
            return $rows;
        }
        // A page past the last priced listing needs their count to know where it starts.
        $before = $rows === [] && $offset > 0
            ? self::total(SearchCompiler::count(SearchCompiler::results($count, null, true)))
            : $offset + count($rows);
        $rest->ordering = $ordering->unpricedRest(max(0, $offset - $before), $size - count($rows));

        return array_merge($rows, self::rows(SearchCompiler::results($rest, null)));
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
            $row = Db::selectOne($statement[0], $statement[1]);

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
