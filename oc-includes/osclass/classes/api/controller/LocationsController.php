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

namespace mindstellar\api\controller;

use mindstellar\api\ApiServices;
use mindstellar\api\auth\Credential;

use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\LocationSerializer;
use mindstellar\database\QueryBuilder;

/**
 * `GET /countries`, `/countries/{code}/regions`, `/regions/{id}/cities`, `/cities/{id}/areas`:
 * by name, `?q=` keeping names that start with it, paged (500 a page, up to 1000).
 */
final class LocationsController
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT     = 1000;

    private LocationSerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->serializer = new LocationSerializer();
    }

    /**
     * @param array<string,string> $args
     */
    public function countries(Request $request, Credential $credential, array $args): Response
    {
        return $this->page($request, 'countries', osc_db_table(DB_TABLE_PREFIX . 't_country'), 'country', null);
    }

    /**
     * @param array<string,string> $args
     */
    public function regions(Request $request, Credential $credential, array $args): Response
    {
        $code = strtoupper((string) ($args['code'] ?? ''));
        if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
            throw ProblemException::of('not_found', 'No such country.');
        }
        $query = osc_db_table(DB_TABLE_PREFIX . 't_region')->where('fk_c_country_code', $code)->where('b_active', 1);

        return $this->page($request, 'countries/' . $code . '/regions', $query, 'region', ['t_country', 'pk_c_code', $code, 'No such country.']);
    }

    /**
     * @param array<string,string> $args
     */
    public function cities(Request $request, Credential $credential, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $query = osc_db_table(DB_TABLE_PREFIX . 't_city')->where('fk_i_region_id', $id)->where('b_active', 1);

        return $this->page($request, 'regions/' . $id . '/cities', $query, 'city', ['t_region', 'pk_i_id', $id, 'No such region.']);
    }

    /**
     * @param array<string,string> $args
     */
    public function areas(Request $request, Credential $credential, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $query = osc_db_table(DB_TABLE_PREFIX . 't_city_area')->where('fk_i_city_id', $id);

        return $this->page($request, 'cities/' . $id . '/areas', $query, 'area', ['t_city', 'pk_i_id', $id, 'No such city.']);
    }

    /**
     * One page of a list. The parent is looked up only when the page is empty, to tell an
     * unknown parent (404) from one with nothing in it.
     *
     * @param string                                   $shape  the LocationSerializer method
     * @param array{0:string,1:string,2:int|string,3:string}|null $parent table, column, value, 404 detail
     */
    private function page(Request $request, string $path, QueryBuilder $query, string $shape, ?array $parent): Response
    {
        $pager  = Pager::fromRequest($request, $this->api->cursor(), new ListSpec(['name'], 'name', 'asc', self::DEFAULT_LIMIT, self::MAX_LIMIT, maxOffset: 100000), ['list' => $path] + $request->query());
        $prefix = trim($request->queryString('q'));
        $query  = $query->orderBy('s_name')->orderBy('pk_' . ($shape === 'country' ? 'c_code' : 'i_id'));
        if ($prefix !== '') {
            $query = $query->like('s_name', $prefix, 'after');
        }
        $rows = osc_db_stringify_rows($query->limit($pager->limit() + 1)->offset($pager->offset())->get());
        if ($rows === [] && $parent !== null && osc_db_table(DB_TABLE_PREFIX . $parent[0])->where($parent[1], $parent[2])->count() === 0) {
            throw ProblemException::of('not_found', $parent[3]);
        }
        $next = $pager->next($rows);
        $data = array_map([$this->serializer, $shape], $pager->page($rows));

        return (new Page($data, null, $pager->limit(), $next))->response($this->api->links(), $path, $request->query());
    }
}
