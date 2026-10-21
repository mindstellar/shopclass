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
 * Search conditions on t_item_facet, matching keys and values through t_facet_term. Builds SQL
 * and runs nothing; callers check FacetIndex::ready() first. Keys and values are cut as
 * FacetIndex writes them, so a filter compares the text the index holds.
 */
final class FacetFilter
{
    public const MAX_NAMES = 3;

    private const ALIASES = ['a', 'b', 'c'];

    /**
     * The per-row probe for `option[...]`, with `in_stock` and `on_sale`: one variant of the
     * listing holds a value of every name (or within a name, and across names) and meets both
     * flags. A probe, not EXISTS or IN, so the planner keeps the listing order and probes the
     * primary key per row. For Search::addCondition(): no quote in the SQL, every value a `?`.
     *
     * @param array<string,string[]> $options name => values, at most MAX_NAMES names
     *
     * @return array{0:string,1:array<int,int|string>} the SQL and its values in order
     * @throws InvalidArgumentException for no name, too many names or a name with no value
     */
    public static function optionProbe(array $options, bool $inStock, bool $onSale): array
    {
        if ($options === [] || count($options) > self::MAX_NAMES) {
            throw new InvalidArgumentException('FacetFilter::optionProbe(): 1 to ' . self::MAX_NAMES . ' option names.');
        }
        $facet = FacetIndex::tableName();
        $term  = FacetIndex::termTable();
        $item  = ListingQuery::tableName();
        $joins = [];
        $where = [];
        $first = [];
        $later = [];
        $i     = 0;
        foreach ($options as $name => $values) {
            $values = array_values((array) $values);
            if ($values === []) {
                throw new InvalidArgumentException('FacetFilter::optionProbe(): option ' . $name . ' has no value.');
            }
            $alias = self::ALIASES[$i++];
            $match = 't' . $alias . '.s_key = ? AND t' . $alias . '.s_value ' . self::oneOrIn(count($values));
            $named = 'JOIN ' . $term . ' t' . $alias . ' ON t' . $alias . '.pk_i_id = ' . $alias . '.fk_i_term_id';
            $bound = array_merge([FacetIndex::optionKey((string) $name)], array_map(static fn ($v): string => FacetIndex::cut((string) $v), $values));
            if ($alias === 'a') {
                array_unshift($joins, $named);
                $where[] = $match . ' AND a.fk_i_item_id = ' . $item . '.pk_i_id';
                $first   = $bound;
            } else {
                $joins[] = 'JOIN ' . $facet . ' ' . $alias . ' ON ' . $alias . '.fk_i_item_id = a.fk_i_item_id AND ' . $alias . '.fk_i_variant_id = a.fk_i_variant_id';
                $joins[] = $named . ' AND ' . $match;
                $later   = array_merge($later, $bound);
            }
        }
        if ($inStock || $onSale) {
            $joins[] = 'JOIN ' . VariantStore::tableName() . ' v ON v.pk_i_id = a.fk_i_variant_id';
        }
        if ($inStock) {
            $where[] = '(v.i_stock IS NULL OR v.i_stock > 0)';
        }
        if ($onSale) {
            $where[] = 'v.i_compare_price > COALESCE(v.i_price, ' . $item . '.i_price)';
        }
        $sql = '(SELECT 1 FROM ' . $facet . ' a ' . implode(' ', $joins) . ' '
            . 'WHERE ' . implode(' AND ', $where) . ' LIMIT 1) IS NOT NULL';

        return [$sql, array_merge($later, $first)];
    }

    /**
     * A dropdown or radio field holds one of these values.
     *
     * @param string[] $values
     *
     * @throws InvalidArgumentException for no value
     */
    public static function fieldIn(int $fieldId, array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('FacetFilter::fieldIn(): no value.');
        }
        $quoted = array_map(static fn ($v): string => self::quote(FacetIndex::cut((string) $v)), array_values($values));
        $match  = count($quoted) === 1 ? '= ' . $quoted[0] : 'IN (' . implode(', ', $quoted) . ')';

        return self::byKey(FacetIndex::key($fieldId), 't.s_value ' . $match);
    }

    /**
     * A checkbox field is ticked.
     */
    public static function fieldFlag(int $fieldId): string
    {
        return self::byKey(FacetIndex::key($fieldId), "t.s_value = '1'");
    }

    /**
     * A number or date field is within min and max, both included.
     *
     * @throws InvalidArgumentException for a bound that is not finite
     */
    public static function fieldRange(int $fieldId, float $min, float $max): string
    {
        return self::byKey(FacetIndex::key($fieldId), "t.s_value = '' AND f.d_number >= " . self::number($min) . ' AND f.d_number <= ' . self::number($max));
    }

    /**
     * A date interval field covers the asked interval: it starts on or before $from and ends
     * on or after $to.
     */
    public static function fieldCovers(int $fieldId, int $from, int $to): string
    {
        $facet = FacetIndex::tableName();
        $term  = FacetIndex::termTable();

        return ListingQuery::tableName() . '.pk_i_id IN (SELECT a.fk_i_item_id FROM ' . $facet . ' a JOIN ' . $term . ' ta ON ta.pk_i_id = a.fk_i_term_id'
            . ' JOIN ' . $facet . ' b ON b.fk_i_item_id = a.fk_i_item_id AND b.d_number >= ' . $to
            . ' JOIN ' . $term . ' tb ON tb.pk_i_id = b.fk_i_term_id AND tb.s_key = ' . self::quote(FacetIndex::key($fieldId, 'to')) . " AND tb.s_value = ''"
            . ' WHERE ta.s_key = ' . self::quote(FacetIndex::key($fieldId, 'from')) . " AND ta.s_value = '' AND a.d_number <= " . $from . ')';
    }

    private static function byKey(string $key, string $match): string
    {
        return ListingQuery::tableName() . '.pk_i_id IN (SELECT f.fk_i_item_id FROM ' . FacetIndex::tableName() . ' f JOIN ' . FacetIndex::termTable()
            . ' t ON t.pk_i_id = f.fk_i_term_id WHERE t.s_key = ' . self::quote($key) . ' AND ' . $match . ')';
    }

    private static function oneOrIn(int $count): string
    {
        return $count === 1 ? '= ?' : 'IN (' . SqlValue::placeholders($count) . ')';
    }

    /**
     * Always quoted: a bare number against s_value makes MySQL cast the column and skip the index.
     */
    private static function quote(string $value): string
    {
        return "'" . SqlValue::escape($value) . "'";
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
