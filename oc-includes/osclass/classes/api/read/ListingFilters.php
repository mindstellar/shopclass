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

namespace mindstellar\api\read;

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;

/**
 * The filters of a listing search, turned into the search page's own parameters
 * (`sCategory`, `sPattern`, ...) so SearchCriteria, the `search_pattern` filter and
 * `search_conditions` listeners see what they see on the page. Sorting is ListingSort's,
 * paging Pager's.
 */
final class ListingFilters
{
    /** API filter => search page parameter. */
    private const LISTS = ['category' => 'sCategory', 'country' => 'sCountry', 'region' => 'sRegion', 'city' => 'sCity', 'city_area' => 'sCityArea'];

    private function __construct()
    {
    }

    /**
     * The search page parameters for a request's filters.
     *
     * @param string   $locale the request's resolved locale, used for category slugs
     * @param int|null $userId a seller the list is fixed to (`/users/{id}/listings`)
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for an unknown category
     */
    public static function params(Request $request, CategoryCatalog $categories, string $locale, ?int $userId = null): array
    {
        $params = ['sPattern' => trim($request->queryString('q'))];
        foreach (self::LISTS as $name => $param) {
            $values = $request->queryList($name);
            if ($name === 'category') {
                $values = self::categoryIds($values, $categories, $locale);
            }
            if ($values !== []) {
                $params[$param] = $values;
            }
        }
        $users = $userId !== null ? [(string) $userId] : array_values(array_filter($request->queryList('user'), 'ctype_digit'));
        if ($users !== []) {
            $params['sUser'] = $users;
        }
        if ($request->queryString('locale') !== '') {
            $params['sLocale'] = $locale;
        }
        foreach (['price_min' => 'sPriceMin', 'price_max' => 'sPriceMax'] as $name => $param) {
            if ($request->queryString($name) !== '') {
                $params[$param] = $request->queryInt($name);
            }
        }
        if ($request->queryBool('with_photos')) {
            $params['bPic'] = 1;
        }
        if ($request->queryBool('premium')) {
            $params['bPremium'] = 1;
        }
        $meta = $request->query()['field'] ?? null;
        if (is_array($meta) && $meta !== []) {
            $params['meta'] = $meta;
        }

        return $params;
    }

    /**
     * Category ids for ids or slugs; an unknown one is refused, so a typo never widens the
     * search to every category.
     *
     * @param string[] $values
     *
     * @return string[]
     * @throws ProblemException 422
     */
    private static function categoryIds(array $values, CategoryCatalog $categories, string $locale): array
    {
        $ids = [];
        foreach ($values as $value) {
            $category = $categories->lookup($value, $locale);
            if ($category === null) {
                throw ProblemException::from(Problem::validation([
                    ['pointer' => '/category', 'code' => 'enum', 'message' => 'is not a known category: ' . $value, 'in' => 'query'],
                ]));
            }
            $ids[] = (string) $category['pk_i_id'];
        }

        return $ids;
    }
}
