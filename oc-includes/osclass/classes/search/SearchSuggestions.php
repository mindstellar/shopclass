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
use mindstellar\listing\RelatedListings;

/**
 * Suggestions while someone types: listings for the search box and categories for the
 * post form. A plugin may answer through `search_suggestions` and `category_suggestions`;
 * core runs its own keyword search. Core never suggests what other people searched.
 */
final class SearchSuggestions
{
    public const MAX_LIMIT = 10;

    public const CATEGORY_LIMIT = 3;

    /** Shorter input suggests nothing. */
    private const MIN_LENGTH = 2;

    /** How many keyword matches core counts per category. */
    private const CATEGORY_POOL = 50;

    /**
     * Live listings for the search box, best first, and any searches a plugin adds.
     *
     * @return array{listings:array<int,array<string,mixed>>,searches:array<int,array{term:string,count:int}>}
     * @throws \mindstellar\database\DbException
     */
    public static function forQuery(string $q, int $limit, string $context = 'api'): array
    {
        $q     = trim($q);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        if (mb_strlen($q, 'UTF-8') < self::MIN_LENGTH) {
            return ['listings' => [], 'searches' => []];
        }

        $answer   = osc_apply_filter('search_suggestions', null, $q, $limit, $context);
        $ids      = is_array($answer) ? RelatedListings::cleanIds((array) ($answer['listings'] ?? [])) : self::listingIds($q, $limit);
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
        if (mb_strlen($title, 'UTF-8') < self::MIN_LENGTH) {
            return [];
        }
        $picked = osc_apply_filter('category_suggestions', null, $title, self::CATEGORY_LIMIT);
        $ids    = is_array($picked) ? RelatedListings::cleanIds($picked) : self::categoryIds($title);

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
     * Core's keyword search, titles that start with the input first.
     *
     * @return int[]
     */
    private static function listingIds(string $q, int $limit): array
    {
        $rows   = self::keywordSearch($q, $limit * 3, true);
        $starts = [];
        $rest   = [];
        foreach ($rows as $row) {
            if (mb_stripos((string) ($row['s_title'] ?? ''), $q, 0, 'UTF-8') === 0) {
                $starts[] = (int) $row['pk_i_id'];
            } else {
                $rest[] = (int) $row['pk_i_id'];
            }
        }

        return array_slice([...$starts, ...$rest], 0, $limit);
    }

    /**
     * The categories the best keyword matches for a title are in, the most common first.
     *
     * @return int[]
     */
    private static function categoryIds(string $title): array
    {
        $counts = [];
        foreach (self::keywordSearch($title, self::CATEGORY_POOL, false) as $row) {
            $id          = (int) ($row['fk_i_category_id'] ?? 0);
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        unset($counts[0]);
        arsort($counts);

        return array_keys($counts);
    }

    /**
     * Live listings matching $pattern, most relevant first, through the site's own search.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function keywordSearch(string $pattern, int $limit, bool $withText): array
    {
        $search = new \Search();
        $search->addPattern($pattern);
        $search->order('relevance', 'DESC');
        $search->limit(0, $limit);

        return $search->doSearch($withText, false);
    }

    /**
     * @param array<mixed> $given
     *
     * @return array<int,array{term:string,count:int}>
     */
    private static function searches(array $given, int $limit): array
    {
        $out = [];
        foreach ($given as $search) {
            $term = is_array($search) && is_string($search['term'] ?? null) ? trim(strip_tags($search['term'])) : '';
            if ($term !== '') {
                $out[] = ['term' => $term, 'count' => max(0, (int) ($search['count'] ?? 0))];
            }
        }

        return array_slice($out, 0, $limit);
    }
}
