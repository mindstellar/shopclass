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
use mindstellar\api\ApiServices;
use mindstellar\api\auth\Credential;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
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
     *
     * @param array<string,string> $args
     */
    public function createRegion(Request $request, Credential $credential, array $args): Response
    {
        $input = $request->input();
        $code  = strtoupper((string) $input['country']);
        $id    = (int) $this->write(fn () => $this->locations->addRegion($code, AdminText::clean($input['name'])));

        return Response::created($this->serializer->region($this->row(LocationQuery::REGION, $id)), $this->api->links()->api('admin/regions/' . $id));
    }

    /**
     * GET /admin/regions/{id}
     *
     * @param array<string,string> $args
     */
    public function showRegion(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->serializer->region($this->row(LocationQuery::REGION, (int) $args['id'])));
    }

    /**
     * PATCH /admin/regions/{id}. A slug not sent is kept.
     *
     * @param array<string,string> $args
     */
    public function updateRegion(Request $request, Credential $credential, array $args): Response
    {
        $row   = $this->row(LocationQuery::REGION, (int) $args['id']);
        $input = $request->input();
        $this->write(fn () => $this->locations->editRegion((int) $row['pk_i_id'], (array_key_exists('name', $input) ? AdminText::clean($input['name']) : (string) $row['s_name']), self::slug($input, $row)));

        return Response::ok($this->serializer->region($this->row(LocationQuery::REGION, (int) $row['pk_i_id'])));
    }

    /**
     * DELETE /admin/regions/{id}
     *
     * @param array<string,string> $args
     */
    public function deleteRegion(Request $request, Credential $credential, array $args): Response
    {
        $this->locations->delete('region', (int) $args['id']);

        return Response::noContent();
    }

    /**
     * POST /admin/cities
     *
     * @param array<string,string> $args
     */
    public function createCity(Request $request, Credential $credential, array $args): Response
    {
        $input  = $request->input();
        $region = (int) $input['region_id'];
        $id     = (int) $this->write(fn () => $this->locations->addCity($region, AdminText::clean($input['name'])));

        return Response::created($this->serializer->city($this->row(LocationQuery::CITY, $id)), $this->api->links()->api('admin/cities/' . $id));
    }

    /**
     * GET /admin/cities/{id}
     *
     * @param array<string,string> $args
     */
    public function showCity(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->serializer->city($this->row(LocationQuery::CITY, (int) $args['id'])));
    }

    /**
     * PATCH /admin/cities/{id}. A slug not sent is kept.
     *
     * @param array<string,string> $args
     */
    public function updateCity(Request $request, Credential $credential, array $args): Response
    {
        $row   = $this->row(LocationQuery::CITY, (int) $args['id']);
        $input = $request->input();
        $this->write(fn () => $this->locations->editCity((int) $row['pk_i_id'], (array_key_exists('name', $input) ? AdminText::clean($input['name']) : (string) $row['s_name']), self::slug($input, $row)));

        return Response::ok($this->serializer->city($this->row(LocationQuery::CITY, (int) $row['pk_i_id'])));
    }

    /**
     * DELETE /admin/cities/{id}
     *
     * @param array<string,string> $args
     */
    public function deleteCity(Request $request, Credential $credential, array $args): Response
    {
        $this->locations->delete('city', (int) $args['id']);

        return Response::noContent();
    }

    /**
     * POST /admin/areas
     *
     * @param array<string,string> $args
     */
    public function createArea(Request $request, Credential $credential, array $args): Response
    {
        $input = $request->input();
        $city  = (int) $input['city_id'];
        $id    = (int) $this->write(fn () => $this->locations->addArea($city, AdminText::clean($input['name'])));

        return Response::created($this->serializer->area($this->row(LocationQuery::AREA, $id)), $this->api->links()->api('admin/areas/' . $id));
    }

    /**
     * GET /admin/areas/{id}
     *
     * @param array<string,string> $args
     */
    public function showArea(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->serializer->area($this->row(LocationQuery::AREA, (int) $args['id'])));
    }

    /**
     * PATCH /admin/areas/{id}
     *
     * @param array<string,string> $args
     */
    public function updateArea(Request $request, Credential $credential, array $args): Response
    {
        $row   = $this->row(LocationQuery::AREA, (int) $args['id']);
        $input = $request->input();
        $this->write(fn () => $this->locations->editArea((int) $row['pk_i_id'], AdminText::clean($input['name'])));

        return Response::ok($this->serializer->area($this->row(LocationQuery::AREA, (int) $row['pk_i_id'])));
    }

    /**
     * DELETE /admin/areas/{id}
     *
     * @param array<string,string> $args
     */
    public function deleteArea(Request $request, Credential $credential, array $args): Response
    {
        $this->locations->delete('area', (int) $args['id']);

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
        return (new LocationQuery())->find($level, $id) ?? throw ProblemException::of('not_found', 'No such location.');
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
