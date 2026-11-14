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
use mindstellar\cache\CacheGroup;
use mindstellar\database\Db;
use mindstellar\listing\ListingQuery;
use mindstellar\listing\VariantStore;
use mindstellar\search\FacetIndex;

/**
 * Search conditions on t_item_facet by term id. Most methods build SQL only; optionIds() and
 * fieldIds() also run one capped read of the matching listing ids. Callers check
 * FacetIndex::ready() first and resolve keys and values with FacetTerms::termIds(), so the SQL
 * holds integers only. Each shape has a page form and a count form: the page probes the
 * primary key per listing, so it stops after one page; the count reads the facet range once.
 * A text term always carries `d_number = 0`, which makes the probe a primary key lookup.
 */
final class FacetFilter
{
    public const MAX_NAMES = 3;

    /** No listing has an unknown term. */
    public const NONE = '1 = 0';

    /** NONE as page text and count text. */
    public const NO_MATCH = [self::NONE, self::NONE];

    private const ALIASES = ['a', 'b', 'c'];

    /**
     * A match set of at most this many listings is read once and searched as ids. Reading 500 ids
     * takes about a millisecond, and past that the probe finds a page within a few listings anyway.
     */
    private const FEW = 500;

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
        $item = ListingQuery::tableName();
        $set  = self::optionSet($terms, $inStock, $onSale, $item);
        if ($set === null) {
            return [...self::NO_MATCH, []];
        }
        [$from, $where, $params] = $set;
        $page  = '(SELECT 1 ' . $from . 'WHERE ' . implode(' AND ', array_merge([$where[0], 'a.fk_i_item_id = ' . $item . '.pk_i_id'], array_slice($where, 1))) . ' LIMIT 1) IS NOT NULL';
        $count = $item . '.pk_i_id IN (SELECT a.fk_i_item_id ' . $from . 'WHERE ' . implode(' AND ', $where) . ')';

        return [$page, $count, $params];
    }

    /**
     * The listings optionProbe() matches when there are at most FEW, else null. listingIn() of them
     * is faster than the probe, which reads listing after listing to find a few. With `in_stock`
     * or `on_sale` the ids are read each time, as a sale or a price change does not drop the search cache.
     *
     * @param array<string,int[]> $terms name => term ids, at most MAX_NAMES names
     *
     * @return int[]|null
     * @throws InvalidArgumentException for no name or too many names
     */
    public static function optionIds(array $terms, bool $inStock, bool $onSale): ?array
    {
        $set = self::optionSet($terms, $inStock, $onSale, 'i');
        if ($set === null) {
            return [];
        }

        return self::fewIds('SELECT DISTINCT a.fk_i_item_id ' . $set[0] . 'WHERE ' . implode(' AND ', $set[1]), $set[2], $inStock || $onSale);
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
            return self::NO_MATCH;
        }
        $match = self::fieldMatch($termIds);
        $item  = ListingQuery::tableName();
        $facet = FacetIndex::tableName();

        return [
            '(SELECT 1 FROM ' . $facet . ' WHERE ' . $match . ' AND fk_i_item_id = ' . $item . '.pk_i_id AND fk_i_variant_id = 0 LIMIT 1) IS NOT NULL',
            $item . '.pk_i_id IN (SELECT fk_i_item_id FROM ' . $facet . ' WHERE ' . $match . ')',
        ];
    }

    /**
     * The listings fieldIn() matches when there are at most FEW, else null.
     *
     * @param int[] $termIds
     *
     * @return int[]|null
     */
    public static function fieldIds(array $termIds): ?array
    {
        return $termIds === [] ? [] : self::fewIds('SELECT DISTINCT fk_i_item_id FROM ' . FacetIndex::tableName() . ' WHERE ' . self::fieldMatch($termIds), [], false);
    }

    /**
     * One of these listings, from optionIds() or fieldIds(): page text, count text; NONE for none.
     *
     * @param int[] $ids
     *
     * @return array{0:string,1:string}
     */
    public static function listingIn(array $ids): array
    {
        if ($ids === []) {
            return self::NO_MATCH;
        }
        $sql = ListingQuery::tableName() . '.pk_i_id IN (' . implode(', ', array_map('intval', $ids)) . ')';

        return [$sql, $sql];
    }

    /**
     * A number or date field is within min and max, both included. The range reads the primary
     * key, so page and count share it.
     *
     * @return array{0:string,1:string} page text, count text; NONE for no term
     * @throws InvalidArgumentException for a bound that is not finite
     */
    public static function fieldRange(?int $termId, float $min, float $max): array
    {
        if ($termId === null) {
            return self::NO_MATCH;
        }
        $sql = ListingQuery::tableName() . '.pk_i_id IN (SELECT fk_i_item_id FROM ' . FacetIndex::tableName()
            . ' WHERE fk_i_term_id = ' . $termId . ' AND d_number >= ' . self::number($min) . ' AND d_number <= ' . self::number($max) . ')';

        return [$sql, $sql];
    }

    /**
     * A date interval field covers the asked interval: it starts on or before $from and ends
     * on or after $to.
     *
     * @return array{0:string,1:string} page text, count text; NONE when either term is missing
     */
    public static function fieldCovers(?int $fromTermId, ?int $toTermId, int $from, int $to): array
    {
        if ($fromTermId === null || $toTermId === null) {
            return self::NO_MATCH;
        }
        $facet = FacetIndex::tableName();
        $sql   = ListingQuery::tableName() . '.pk_i_id IN (SELECT a.fk_i_item_id FROM ' . $facet . ' a'
            . ' JOIN ' . $facet . ' b ON b.fk_i_term_id = ' . $toTermId . ' AND b.fk_i_item_id = a.fk_i_item_id AND b.d_number >= ' . $to
            . ' WHERE a.fk_i_term_id = ' . $fromTermId . ' AND a.d_number <= ' . $from . ')';

        return [$sql, $sql];
    }

    /**
     * The FROM text, the WHERE terms (the first term's match first) and the values of an option
     * match set; null when a name has no term. $price is the table the listing's own price is
     * read from: the outer t_item, or `i` joined in so the set stands alone.
     *
     * @param array<string,int[]> $terms
     *
     * @return array{0:string,1:string[],2:array<int,int>}|null
     * @throws InvalidArgumentException for no name or too many names
     */
    private static function optionSet(array $terms, bool $inStock, bool $onSale, string $price): ?array
    {
        if ($terms === [] || count($terms) > self::MAX_NAMES) {
            throw new InvalidArgumentException('FacetFilter: an option filter takes 1 to ' . self::MAX_NAMES . ' option names.');
        }
        $facet = FacetIndex::tableName();
        $joins = [];
        $where = [];
        $first = [];
        $later = [];
        $i     = 0;
        foreach ($terms as $ids) {
            $ids = array_map('intval', array_values((array) $ids));
            if ($ids === []) {
                return null;
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
            // The item id lets a few variants on sale lead the join through the facet key.
            $joins[] = 'JOIN ' . VariantStore::tableName() . ' v ON v.pk_i_id = a.fk_i_variant_id AND v.fk_i_item_id = a.fk_i_item_id';
        }
        if ($onSale && $price === 'i') {
            $joins[] = 'JOIN ' . ListingQuery::tableName() . ' i ON i.pk_i_id = a.fk_i_item_id';
        }
        $where = array_merge($where, self::variantFlags('v', $price, $inStock, $onSale));

        return ['FROM ' . $facet . ' a ' . ($joins === [] ? '' : implode(' ', $joins) . ' '), $where, array_merge($later, $first)];
    }

    /**
     * The `in_stock` and `on_sale` terms for variant table $variant of listing table $item. The
     * `IS NOT NULL` lets idx_compare_price find the variants on sale.
     *
     * @return string[]
     */
    public static function variantFlags(string $variant, string $item, bool $inStock, bool $onSale): array
    {
        $where = [];
        if ($inStock) {
            $where[] = '(' . $variant . '.i_stock IS NULL OR ' . $variant . '.i_stock > 0)';
        }
        if ($onSale) {
            $where[] = $variant . '.i_compare_price IS NOT NULL AND ' . $variant . '.i_compare_price > COALESCE(' . $variant . '.i_price, ' . $item . '.i_price)';
        }

        return $where;
    }

    /**
     * @param int[] $termIds
     */
    private static function fieldMatch(array $termIds): string
    {
        return 'fk_i_term_id ' . self::oneOrIn(count($termIds), array_map('intval', array_values($termIds))) . ' AND d_number = 0';
    }

    /**
     * The ids `$sql` selects when there are at most FEW, else null. Kept with the search results,
     * which a listing change drops; with $live only the "more than FEW" answer is kept.
     *
     * @param array<int,int> $params
     *
     * @return int[]|null
     * @throws \mindstellar\database\DbException
     */
    private static function fewIds(string $sql, array $params, bool $live): ?array
    {
        $sql .= ' LIMIT ' . (self::FEW + 1);
        $key  = 'facet-ids|' . md5($sql . '|' . implode(',', $params));
        $read = static function () use ($sql, $params): array {
            $ids = array_map('intval', array_column(Db::select($sql, $params), 'fk_i_item_id'));
            sort($ids);

            return count($ids) > self::FEW ? [] : ['ids' => $ids];
        };
        if (!$live) {
            $ids = CacheGroup::remember('search', $key, $read);
        } elseif (CacheGroup::remember('search', $key, static fn (): ?array => null) === []) {
            // Only "more than FEW" is kept: the probe that then runs reads the live rows.
            return null;
        } else {
            $ids = $read();
            if ($ids === []) {
                CacheGroup::remember('search', $key, static fn (): array => []);
            }
        }

        return is_array($ids) && isset($ids['ids']) ? $ids['ids'] : null;
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
