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
 * core matches listing titles. Core never suggests what other people searched.
 */
final class SearchSuggestions
{
    public const MAX_LIMIT = 10;

    public const CATEGORY_LIMIT = 3;

    /** Shorter words are not in the title index, so input without one suggests nothing. */
    private const MIN_WORD = 3;

    /** How many title matches core counts per category. */
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
        if (self::words($q) === []) {
            return ['listings' => [], 'searches' => []];
        }

        $answer   = osc_apply_filter('search_suggestions', null, $q, $limit, $context);
        $ids      = is_array($answer) ? RelatedListings::cleanIds((array) ($answer['listings'] ?? [])) : (new ListingQuery())->titleMatches(self::words($q), $q, $limit);
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
        if (self::words($title) === []) {
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
     * The categories of the live listings whose titles match, the most common first.
     *
     * @return int[]
     */
    private static function categoryIds(string $title): array
    {
        $query  = new ListingQuery();
        $counts = [];
        foreach ($query->findMany($query->titleMatches(self::words($title), $title, self::CATEGORY_POOL)) as $row) {
            $id          = (int) ($row['fk_i_category_id'] ?? 0);
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        unset($counts[0]);
        arsort($counts);

        return array_keys($counts);
    }

    /**
     * The input's words the title index holds: three letters or more, without
     * full-text operators, at most five.
     *
     * @return string[]
     */
    private static function words(string $input): array
    {
        $words = [];
        foreach (preg_split('/[^\pL\pN]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word, 'UTF-8') >= self::MIN_WORD) {
                $words[mb_strtolower($word, 'UTF-8')] = $word;
            }
        }

        return array_slice(array_values($words), 0, 5);
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
