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

namespace mindstellar\search;

use mindstellar\listing\ListingQuery;

/**
 * Suggestions while someone types: listings for the search box and categories for the
 * post form. A plugin may answer through `search_suggestions` and `category_suggestions`;
 * core matches listing titles. Core never suggests what other people searched.
 */
final class SearchSuggestions
{
    public const MAX_LIMIT = 10;

    public const CATEGORY_LIMIT = 3;

    /** How many title matches core counts per category. */
    private const CATEGORY_POOL = 50;

    /**
     * Live listings for the search box, best first, and any searches a plugin adds.
     *
     * @return array{listings:array<int,array<string,mixed>>,searches:array<int,array{term:string,count:int,count_label:string}>}
     * @throws \mindstellar\database\DbException
     */
    public static function forQuery(string $q, int $limit, string $context = 'api'): array
    {
        $q     = trim($q);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        if (ListingQuery::titleWords($q) === []) {
            return ['listings' => [], 'searches' => []];
        }

        $answer   = osc_apply_filter('search_suggestions', null, $q, $limit, $context);
        $ids      = is_array($answer) ? ListingQuery::cleanIds((array) ($answer['listings'] ?? [])) : self::listingIds($q, $limit);
        $searches = is_array($answer) ? self::searches((array) ($answer['searches'] ?? []), $limit) : [];

        return [
            'listings' => array_slice((new ListingQuery())->findLive($ids), 0, $limit),
            'searches' => $searches,
        ];
    }

    /**
     * Up to CATEGORY_LIMIT enabled categories for a listing title, best first.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function categoriesForTitle(string $title): array
    {
        $title = trim($title);
        if (ListingQuery::titleWords($title) === []) {
            return [];
        }
        $picked = osc_apply_filter('category_suggestions', null, $title, self::CATEGORY_LIMIT);
        $ids    = is_array($picked) ? ListingQuery::cleanIds($picked) : self::categoryIds($title);

        $out = [];
        foreach ($ids as $id) {
            $category = \Category::getInstance()->findByPrimaryKey($id);
            if (is_array($category) && (int) ($category['b_enabled'] ?? 0) === 1) {
                $out[] = ['id' => $id, 'name' => (string) ($category['s_name'] ?? '')];
            }
            if (count($out) === self::CATEGORY_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * Titles with every word first; titles with only some of them fill the rest.
     *
     * @return int[]
     * @throws \mindstellar\database\DbException
     */
    private static function listingIds(string $q, int $limit): array
    {
        $query = new ListingQuery();
        $words = ListingQuery::titleWords($q);
        $ids   = $query->titleMatches($words, $q, $limit);
        if (count($ids) < $limit && count($words) > 1) {
            $ids = array_slice(array_values(array_unique([...$ids, ...$query->titleMatches($words, $q, $limit, false)])), 0, $limit);
        }

        return $ids;
    }

    /**
     * The categories of the live listings whose titles match, the most common first.
     *
     * @return int[]
     */
    private static function categoryIds(string $title): array
    {
        $query  = new ListingQuery();
        $counts = [];
        foreach ($query->findMany($query->titleMatches(ListingQuery::titleWords($title), $title, self::CATEGORY_POOL, false)) as $row) {
            $id          = (int) ($row['fk_i_category_id'] ?? 0);
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        unset($counts[0]);
        arsort($counts);

        return array_keys($counts);
    }

    /**
     * @param array<mixed> $given
     *
     * @return array<int,array{term:string,count:int,count_label:string}>
     */
    private static function searches(array $given, int $limit): array
    {
        $out = [];
        foreach ($given as $search) {
            $term = is_array($search) && is_string($search['term'] ?? null) ? trim(strip_tags($search['term'])) : '';
            if ($term !== '') {
                $count = max(0, (int) ($search['count'] ?? 0));
                $out[] = ['term' => $term, 'count' => $count, 'count_label' => sprintf(_n('%s listing', '%s listings', $count), $count)];
            }
        }

        return array_slice($out, 0, $limit);
    }
}
