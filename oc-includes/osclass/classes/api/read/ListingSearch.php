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
use mindstellar\api\auth\Credential;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\search\SearchCriteria;
use mindstellar\search\SearchRunner;

/**
 * A page of listings through the search page's own runner, so its filters, hooks, result
 * cache and any search backend apply. Behind `GET /listings` and `GET /users/{id}/listings`.
 */
final class ListingSearch
{
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

        $params = ListingFilters::params($request, $this->reader->categories(), $context->locale(), $userId) + [
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

        return (new Page($data, $result->total(), $pager->limit(), $next))->response($this->api->links(), $path, $request->query());
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
            $search->addCondition(...$sort->after(DB_TABLE_PREFIX . 't_item', $after));
        }
        $search->limit($pager->offset(), $pager->limit() + 1);
    }
}
