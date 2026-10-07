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

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Pager;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\LocationSerializer;
use mindstellar\location\LocationQuery;

/**
 * `GET /countries`, `/countries/{code}/regions`, `/regions/{id}/cities`, `/cities/{id}/areas`:
 * by name, `?q=` keeping names that start with it, paged (500 a page, up to 1000).
 */
final class LocationsController
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT     = 1000;

    private LocationSerializer $serializer;

    private LocationQuery $places;

    public function __construct(private ApiServices $api)
    {
        $this->serializer = new LocationSerializer();
        $this->places     = new LocationQuery();
    }

    public function countries(ApiCall $call): Response
    {
        return $this->page($call->request(), 'countries', 'country', null, fn (string $q, int $limit, int $offset): array => $this->places->countries($q, $limit, $offset));
    }

    public function regions(ApiCall $call): Response
    {
        $code = strtoupper((string) ($call->arg('code') ?? ''));
        if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
            throw ProblemException::notFound('No such country.');
        }

        return $this->page($call->request(), 'countries/' . $code . '/regions', 'region', [LocationQuery::COUNTRY, $code, 'No such country.'], fn (string $q, int $limit, int $offset): array => $this->places->regions($code, $q, $limit, $offset));
    }

    public function cities(ApiCall $call): Response
    {
        $id = $call->intArg();

        return $this->page($call->request(), 'regions/' . $id . '/cities', 'city', [LocationQuery::REGION, $id, 'No such region.'], fn (string $q, int $limit, int $offset): array => $this->places->cities($id, $q, $limit, $offset));
    }

    public function areas(ApiCall $call): Response
    {
        $id = $call->intArg();

        return $this->page($call->request(), 'cities/' . $id . '/areas', 'area', [LocationQuery::CITY, $id, 'No such city.'], fn (string $q, int $limit, int $offset): array => $this->places->areas($id, $q, $limit, $offset));
    }

    /**
     * One page of a list. The parent is looked up only when the page is empty, to tell an
     * unknown parent (404) from one with nothing in it.
     *
     * @param string                                         $shape  the LocationSerializer method
     * @param array{0:string,1:int|string,2:string}|null     $parent LocationQuery level, id, 404 detail
     * @param callable(string,int,int): array<int,array<string,mixed>> $read prefix, limit, offset => rows
     */
    private function page(Request $request, string $path, string $shape, ?array $parent, callable $read): Response
    {
        $pager = Pager::fromRequest($request, $this->api->cursor(), new ListSpec(['name'], 'name', 'asc', self::DEFAULT_LIMIT, self::MAX_LIMIT, maxOffset: 100000), ['list' => $path] + $request->query());

        return $pager->respond(
            function () use ($request, $pager, $parent, $read): array {
                $rows = $read(trim($request->queryString('q')), $pager->limit() + 1, $pager->offset());
                if ($rows === [] && $parent !== null && !$this->places->exists($parent[0], $parent[1])) {
                    throw ProblemException::notFound($parent[2]);
                }

                return $rows;
            },
            null,
            fn (array $page): array => array_map([$this->serializer, $shape], $page),
            $this->api->links(),
            $path,
            $request->query()
        );
    }
}
