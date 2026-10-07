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

namespace mindstellar\api\controller\admin;

use mindstellar\admin\AdminText;
use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\Response;
use mindstellar\api\serializer\LocationSerializer;
use mindstellar\location\LocationQuery;
use mindstellar\location\LocationService;
use mindstellar\utility\DeferredMail;

/**
 * Regions, cities and city areas, written through the LocationService Settings -> Locations
 * uses. Reads go through LocationQuery, straight from the tables, so a change shows at once.
 */
final class AdminLocationsController
{
    private LocationSerializer $serializer;

    private LocationService $locations;

    public function __construct(private ApiServices $api)
    {
        $this->locations = $api->locationService();
        $this->serializer = new LocationSerializer();
    }

    /**
     * POST /admin/regions
     */
    public function createRegion(ApiCall $call): Response
    {
        $input = $call->input();
        $code  = strtoupper((string) $input['country']);
        $id    = (int) $this->write(fn () => $this->locations->addRegion($code, AdminText::clean($input['name'])));

        return Response::created($this->serializer->region($this->row(LocationQuery::REGION, $id)), $this->api->links()->api('admin/regions/' . $id));
    }

    /**
     * GET /admin/regions/{id}
     */
    public function showRegion(ApiCall $call): Response
    {
        return Response::ok($this->serializer->region($this->row(LocationQuery::REGION, $call->intArg())));
    }

    /**
     * PATCH /admin/regions/{id}. A slug not sent is kept.
     */
    public function updateRegion(ApiCall $call): Response
    {
        $row   = $this->row(LocationQuery::REGION, $call->intArg());
        $input = $call->input();
        $this->write(fn () => $this->locations->editRegion((int) $row['pk_i_id'], (array_key_exists('name', $input) ? AdminText::clean($input['name']) : (string) $row['s_name']), self::slug($input, $row)));

        return Response::ok($this->serializer->region($this->row(LocationQuery::REGION, (int) $row['pk_i_id'])));
    }

    /**
     * DELETE /admin/regions/{id}
     */
    public function deleteRegion(ApiCall $call): Response
    {
        $this->locations->delete('region', $call->intArg());

        return Response::noContent();
    }

    /**
     * POST /admin/cities
     */
    public function createCity(ApiCall $call): Response
    {
        $input  = $call->input();
        $region = (int) $input['region_id'];
        $id     = (int) $this->write(fn () => $this->locations->addCity($region, AdminText::clean($input['name'])));

        return Response::created($this->serializer->city($this->row(LocationQuery::CITY, $id)), $this->api->links()->api('admin/cities/' . $id));
    }

    /**
     * GET /admin/cities/{id}
     */
    public function showCity(ApiCall $call): Response
    {
        return Response::ok($this->serializer->city($this->row(LocationQuery::CITY, $call->intArg())));
    }

    /**
     * PATCH /admin/cities/{id}. A slug not sent is kept.
     */
    public function updateCity(ApiCall $call): Response
    {
        $row   = $this->row(LocationQuery::CITY, $call->intArg());
        $input = $call->input();
        $this->write(fn () => $this->locations->editCity((int) $row['pk_i_id'], (array_key_exists('name', $input) ? AdminText::clean($input['name']) : (string) $row['s_name']), self::slug($input, $row)));

        return Response::ok($this->serializer->city($this->row(LocationQuery::CITY, (int) $row['pk_i_id'])));
    }

    /**
     * DELETE /admin/cities/{id}
     */
    public function deleteCity(ApiCall $call): Response
    {
        $this->locations->delete('city', $call->intArg());

        return Response::noContent();
    }

    /**
     * POST /admin/areas
     */
    public function createArea(ApiCall $call): Response
    {
        $input = $call->input();
        $city  = (int) $input['city_id'];
        $id    = (int) $this->write(fn () => $this->locations->addArea($city, AdminText::clean($input['name'])));

        return Response::created($this->serializer->area($this->row(LocationQuery::AREA, $id)), $this->api->links()->api('admin/areas/' . $id));
    }

    /**
     * GET /admin/areas/{id}
     */
    public function showArea(ApiCall $call): Response
    {
        return Response::ok($this->serializer->area($this->row(LocationQuery::AREA, $call->intArg())));
    }

    /**
     * PATCH /admin/areas/{id}
     */
    public function updateArea(ApiCall $call): Response
    {
        $row   = $this->row(LocationQuery::AREA, $call->intArg());
        $input = $call->input();
        $this->write(fn () => $this->locations->editArea((int) $row['pk_i_id'], AdminText::clean($input['name'])));

        return Response::ok($this->serializer->area($this->row(LocationQuery::AREA, (int) $row['pk_i_id'])));
    }

    /**
     * DELETE /admin/areas/{id}
     */
    public function deleteArea(ApiCall $call): Response
    {
        $this->locations->delete('area', $call->intArg());

        return Response::noContent();
    }

    /**
     * Run a write in one transaction; a refusal answers 404 or 422.
     *
     * @param callable(): mixed $write
     *
     * @throws ProblemException
     */
    private function write(callable $write): mixed
    {
        return DeferredMail::transaction($write);
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function row(string $level, int $id): array
    {
        return (new LocationQuery())->find($level, $id) ?? throw ProblemException::notFound('No such location.');
    }

    /**
     * The slug to keep or set: the one sent, else the stored one.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $row
     */
    private static function slug(array $input, array $row): string
    {
        return array_key_exists('slug', $input) ? trim((string) $input['slug']) : (string) ($row['s_slug'] ?? '');
    }
}
