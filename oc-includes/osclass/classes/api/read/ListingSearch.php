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

use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\apiaccess\Credential;
use mindstellar\search\SearchCriteria;
use mindstellar\search\SearchRunner;

/**
 * A page of listings through the search page's own runner, so its filters, hooks, result
 * cache and any search backend apply. Behind `GET /listings` and `GET /users/{id}/listings`.
 * The API's filters become the search page's own parameters (`sCategory`, `sPattern`, ...),
 * so SearchCriteria, the `search_pattern` filter and `search_conditions` listeners see what
 * they see on the page. Sorting is ListingSort's, paging Pager's.
 */
final class ListingSearch
{
    /** API filter => search page parameter. */
    private const LISTS = ['category' => 'sCategory', 'country' => 'sCountry', 'region' => 'sRegion', 'city' => 'sCity', 'city_area' => 'sCityArea'];

    public function __construct(private ApiServices $api, private ListingReader $reader)
    {
    }

    /**
     * @param int|null $userId the seller the list is fixed to
     * @param string   $path   the endpoint, below /api/v1/, for the page links
     */
    public function run(Request $request, Credential $credential, ?int $userId, string $path): Response
    {
        $facts   = $this->api->facts();
        $context = $this->api->context($request, $credential, 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $sort    = ListingSort::fromRequest($request);
        $filters = $request->query();
        if ($userId !== null) {
            $filters['user'] = (string) $userId;
        }
        $pager = Pager::fromRequest($request, $this->api->cursor(), $sort->spec($facts->defaultLimit(), $facts->maxLimit()), $filters);

        $params = self::params($request, $this->reader->categories(), $context->locale(), $userId) + [
            'sOrder'     => $sort->searchOrder(),
            'iOrderType' => $sort->direction(),
            'iPagesize'  => $pager->limit(),
        ];
        $search = new \Search();
        $search->primeResources(false);
        // Listeners on search_conditions and search_results, and plugins reading Params
        // inside them, see the search page's own parameter names while the search runs.
        $result = \Params::withRequest($params, fn () => SearchRunner::run(
            SearchCriteria::fromRequest($params, ['pageSize' => $pager->limit(), 'maxPageSize' => $pager->limit()]),
            $search,
            'api',
            fn (\Search $search) => $this->shape($sort, $pager, $search),
            $pager->counts()
        ));

        $rows = $result->items();
        $page = $pager->page($rows);
        $data = $this->reader->many($page, $context);
        $next = $pager->next($rows);

        return (new Page($data, $result->total(), $pager->limit(), $next, $pager->truncated($rows)))->response($this->api->links(), $path, $request->query());
    }

    /**
     * Finish the search after SearchRunner set its sort and page: a total order (the id
     * breaks ties), the keyset condition, and one row more than the page.
     */
    private function shape(ListingSort $sort, Pager $pager, \Search $search): void
    {
        $columns = $sort->columns();
        if ($columns !== []) {
            $search->orderBy($columns);
        }
        $after = $pager->after();
        if ($after !== null) {
            $search->addCondition(...$sort->after(\Item::getInstance()->getTableName(), $after));
        }
        $search->limit($pager->offset(), $pager->limit() + 1);
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
                $values = array_map('strval', $categories->resolve($values, $locale));
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
        $meta = $request->query()['custom_field'] ?? null;
        if (is_array($meta) && $meta !== []) {
            $params['meta'] = $meta;
        }

        return $params;
    }
}
