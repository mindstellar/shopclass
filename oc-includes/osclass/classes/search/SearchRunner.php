<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\search;

/**
 * Runs a listing search: criteria onto the Search model, sort, page, the
 * `search_conditions` hook, then the results from a backend, the cache or MySQL.
 */
class SearchRunner
{
    /**
     * @param SearchCriteria                  $criteria
     * @param \Search                         $search
     * @param string                          $context passed to `search_conditions`: 'request' for the search page, 'api' for the API
     * @param callable|null                   $shape   fn(\Search): void, run after sort and page are set and before
     *                                                 `search_conditions`; the API adds its total order and keyset here
     * @param bool                            $count   false skips the total, which the result then gives as null
     * @param array<string,mixed>|null        $params  the search parameters listeners get; null for the request's
     * @param (callable(array): array)|null   $extend  makes the bare t_item rows into the caller's rows, in place
     *                                                 of Item::extendData(); the cache then keeps the bare rows
     *
     * @return SearchResult
     */
    public static function run(SearchCriteria $criteria, \Search $search, string $context, ?callable $shape = null, bool $count = true, ?array $params = null, ?callable $extend = null): SearchResult
    {
        SearchBuilder::apply($criteria, $search);

        $search->order($criteria->sortColumn(), $criteria->sortDirection());

        if ($criteria->feed() === 'rss') {
            $search->page(0, $criteria->pageSizeForFeed());
        } else {
            $search->page($criteria->page(), $criteria->pageSize());
        }
        if ($shape !== null) {
            $shape($search);
        }

        SearchBuilder::fireConditions($search, $context, $params);

        // A listener on 'search_results' may answer the query itself (an external engine)
        // with ['items' => array, 'total' => int, 'model' => ?Search], or return null.
        // A backend owns its caching, so core's result cache is skipped when one answers.
        $backend = SearchBuilder::asRequest($params, static fn () => osc_apply_filter('search_results', null, $search, \Params::getParamsAsArray()));
        $model   = $search;
        if (is_array($backend) && isset($backend['items'])) {
            $aItems = $backend['items'];
            $total = (int)($backend['total'] ?? count($aItems));
            if (isset($backend['model']) && $backend['model'] instanceof \Search) {
                $model = $backend['model'];
            }
        } else {
            // The search-cache generation is bumped on every item lifecycle event, so a
            // deleted or disabled listing is never served from a stored result.
            // An uncounted result is cached apart, so a counting search never reads its null total.
            $key   = md5(osc_cache_search_generation() . osc_base_url() . $search->toJson() . ($count ? '' : '|uncounted') . ($extend === null ? '' : '|bare'));
            $found = false;
            $cache = osc_cache_get($key, $found);
            if ($cache) {
                $aItems = $cache['aItems'];
                $total = $cache['iTotalItems'];
            } else {
                $aItems = $search->doSearch($extend === null, $count);
                $total = $count ? $search->count() : null;
                osc_cache_set($key, array('aItems' => $aItems, 'iTotalItems' => $total), OSC_CACHE_TTL);
            }
        }
        if ($extend !== null && $aItems !== array()) {
            $aItems = $extend($aItems);
        }

        $aItems = osc_apply_filter('pre_show_items', $aItems);

        // Highlight/urgent/bump state for the whole page in one query.
        osc_prime_item_upgrades($aItems);

        return new SearchResult($aItems, $total, $model);
    }
}
