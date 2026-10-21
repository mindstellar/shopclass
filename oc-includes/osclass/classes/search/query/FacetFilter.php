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

use InvalidArgumentException;
use mindstellar\listing\ListingQuery;
use mindstellar\listing\VariantStore;
use mindstellar\search\FacetIndex;

/**
 * Search conditions on t_item_facet by term id. Builds SQL and runs nothing; callers check
 * FacetIndex::ready() first and resolve keys and values with FacetIndex::termIds(), so the SQL
 * holds integers only. Each shape has a page form and a count form: the page probes the
 * primary key per listing, so it stops after one page; the count reads the facet range once.
 * A text term always carries `d_number = 0`, which makes the probe a primary key lookup.
 */
final class FacetFilter
{
    public const MAX_NAMES = 3;

    /** No listing has an unknown term. */
    public const NONE = '1 = 0';

    private const ALIASES = ['a', 'b', 'c'];

    /**
     * `option[...]` with `in_stock` and `on_sale`: one variant of the listing holds a term of
     * every name (or within a name, and across names) and meets both flags. For
     * Search::addCondition(): no quote in the SQL, every term id a `?`, the same values for both.
     *
     * @param array<string,int[]> $terms name => term ids, at most MAX_NAMES names
     *
     * @return array{0:string,1:string,2:array<int,int>} page SQL, count SQL, their values in order
     * @throws InvalidArgumentException for no name or too many names
     */
    public static function optionProbe(array $terms, bool $inStock, bool $onSale): array
    {
        if ($terms === [] || count($terms) > self::MAX_NAMES) {
            throw new InvalidArgumentException('FacetFilter::optionProbe(): 1 to ' . self::MAX_NAMES . ' option names.');
        }
        $facet = FacetIndex::tableName();
        $item  = ListingQuery::tableName();
        $joins = [];
        $where = [];
        $first = [];
        $later = [];
        $i     = 0;
        foreach ($terms as $ids) {
            $ids = array_map('intval', array_values((array) $ids));
            if ($ids === []) {
                return [self::NONE, self::NONE, []];
            }
            $alias = self::ALIASES[$i++];
            $match = $alias . '.fk_i_term_id ' . self::oneOrIn(count($ids)) . ' AND ' . $alias . '.d_number = 0';
            if ($alias === 'a') {
                $where[] = $match;
                $first   = $ids;
            } else {
                $joins[] = 'JOIN ' . $facet . ' ' . $alias . ' ON ' . $match . ' AND ' . $alias . '.fk_i_item_id = a.fk_i_item_id AND ' . $alias . '.fk_i_variant_id = a.fk_i_variant_id';
                $later   = array_merge($later, $ids);
            }
        }
        if ($inStock || $onSale) {
            $joins[] = 'JOIN ' . VariantStore::tableName() . ' v ON v.pk_i_id = a.fk_i_variant_id';
        }
        $flags = [];
        if ($inStock) {
            $flags[] = '(v.i_stock IS NULL OR v.i_stock > 0)';
        }
        if ($onSale) {
            $flags[] = 'v.i_compare_price > COALESCE(v.i_price, ' . $item . '.i_price)';
        }
        $from  = 'FROM ' . $facet . ' a ' . ($joins === [] ? '' : implode(' ', $joins) . ' ');
        $page  = '(SELECT 1 ' . $from . 'WHERE ' . implode(' AND ', array_merge($where, ['a.fk_i_item_id = ' . $item . '.pk_i_id'], $flags)) . ' LIMIT 1) IS NOT NULL';
        $count = $item . '.pk_i_id IN (SELECT a.fk_i_item_id ' . $from . 'WHERE ' . implode(' AND ', array_merge($where, $flags)) . ')';

        return [$page, $count, array_merge($later, $first)];
    }

    /**
     * A dropdown or radio field holds one of these terms.
     *
     * @param int[] $termIds
     *
     * @return array{0:string,1:string} page text, count text; NONE for no term
     */
    public static function fieldIn(array $termIds): array
    {
        if ($termIds === []) {
            return [self::NONE, self::NONE];
        }
        $match = 'fk_i_term_id ' . self::oneOrIn(count($termIds), array_map('intval', array_values($termIds))) . ' AND d_number = 0';
        $item  = ListingQuery::tableName();
        $facet = FacetIndex::tableName();

        return [
            '(SELECT 1 FROM ' . $facet . ' WHERE ' . $match . ' AND fk_i_item_id = ' . $item . '.pk_i_id AND fk_i_variant_id = 0 LIMIT 1) IS NOT NULL',
            $item . '.pk_i_id IN (SELECT fk_i_item_id FROM ' . $facet . ' WHERE ' . $match . ')',
        ];
    }

    /**
     * A checkbox field is ticked: its term is the field's key with '1'.
     *
     * @return array{0:string,1:string} page text, count text
     */
    public static function fieldFlag(int $termId): array
    {
        return self::fieldIn([$termId]);
    }

    /**
     * A number or date field is within min and max, both included. The range reads the primary
     * key, so page and count share it.
     *
     * @return array{0:string,1:string} page text, count text
     * @throws InvalidArgumentException for a bound that is not finite
     */
    public static function fieldRange(int $termId, float $min, float $max): array
    {
        $sql = ListingQuery::tableName() . '.pk_i_id IN (SELECT fk_i_item_id FROM ' . FacetIndex::tableName()
            . ' WHERE fk_i_term_id = ' . $termId . ' AND d_number >= ' . self::number($min) . ' AND d_number <= ' . self::number($max) . ')';

        return [$sql, $sql];
    }

    /**
     * A date interval field covers the asked interval: it starts on or before $from and ends
     * on or after $to.
     *
     * @return array{0:string,1:string} page text, count text
     */
    public static function fieldCovers(int $fromTermId, int $toTermId, int $from, int $to): array
    {
        $facet = FacetIndex::tableName();
        $sql   = ListingQuery::tableName() . '.pk_i_id IN (SELECT a.fk_i_item_id FROM ' . $facet . ' a'
            . ' JOIN ' . $facet . ' b ON b.fk_i_term_id = ' . $toTermId . ' AND b.fk_i_item_id = a.fk_i_item_id AND b.d_number >= ' . $to
            . ' WHERE a.fk_i_term_id = ' . $fromTermId . ' AND a.d_number <= ' . $from . ')';

        return [$sql, $sql];
    }

    /**
     * `= x` for one, `IN (x, y)` for more; `?` marks unless the ids are given.
     *
     * @param int[]|null $ids
     */
    private static function oneOrIn(int $count, ?array $ids = null): string
    {
        if ($ids !== null) {
            return $count === 1 ? '= ' . $ids[0] : 'IN (' . implode(', ', $ids) . ')';
        }

        return $count === 1 ? '= ?' : 'IN (' . SqlValue::placeholders($count) . ')';
    }

    /**
     * A float as SQL text that reads back as the same double.
     */
    private static function number(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('FacetFilter::fieldRange(): a bound must be finite.');
        }
        if ($value === floor($value) && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return var_export($value, true);
    }
}
