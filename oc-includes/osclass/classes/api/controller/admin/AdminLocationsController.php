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

/**
 * Regions, cities and city areas, written through the LocationService Settings -> Locations
 * uses. Reads go through LocationQuery, straight from the tables, so a change shows at once.
 */
final class AdminLocationsController
{
    /** Each level's path under the API. */
    private const PATHS = [LocationQuery::REGION => 'admin/regions', LocationQuery::CITY => 'admin/cities', LocationQuery::AREA => 'admin/areas'];

    private LocationSerializer $serializer;

    private LocationService $locations;

    public function __construct(private ApiServices $api)
    {
        $this->locations = $api->locationService();
        $this->serializer = new LocationSerializer();
    }

    public function createRegion(ApiCall $call): Response
    {
        return $this->create($call, LocationQuery::REGION);
    }

    public function showRegion(ApiCall $call): Response
    {
        return $this->show($call, LocationQuery::REGION);
    }

    public function updateRegion(ApiCall $call): Response
    {
        return $this->update($call, LocationQuery::REGION);
    }

    public function deleteRegion(ApiCall $call): Response
    {
        return $this->delete($call, LocationQuery::REGION);
    }

    public function createCity(ApiCall $call): Response
    {
        return $this->create($call, LocationQuery::CITY);
    }

    public function showCity(ApiCall $call): Response
    {
        return $this->show($call, LocationQuery::CITY);
    }

    public function updateCity(ApiCall $call): Response
    {
        return $this->update($call, LocationQuery::CITY);
    }

    public function deleteCity(ApiCall $call): Response
    {
        return $this->delete($call, LocationQuery::CITY);
    }

    public function createArea(ApiCall $call): Response
    {
        return $this->create($call, LocationQuery::AREA);
    }

    public function showArea(ApiCall $call): Response
    {
        return $this->show($call, LocationQuery::AREA);
    }

    public function updateArea(ApiCall $call): Response
    {
        return $this->update($call, LocationQuery::AREA);
    }

    public function deleteArea(ApiCall $call): Response
    {
        return $this->delete($call, LocationQuery::AREA);
    }

    private function create(ApiCall $call, string $level): Response
    {
        $input = $call->input();
        $name  = AdminText::clean($input['name']);
        $id    = match ($level) {
            LocationQuery::REGION => $this->locations->addRegion(strtoupper((string) $input['country']), $name),
            LocationQuery::CITY   => $this->locations->addCity((int) $input['region_id'], $name),
            default               => $this->locations->addArea((int) $input['city_id'], $name),
        };

        return $this->api->created($call, $this->serialize($level, $this->row($level, $id)), self::PATHS[$level] . '/' . $id);
    }

    private function show(ApiCall $call, string $level): Response
    {
        return Response::ok($this->serialize($level, $this->row($level, $call->intArg())));
    }

    /**
     * Members not sent keep their stored values.
     */
    private function update(ApiCall $call, string $level): Response
    {
        $row   = $this->row($level, $call->intArg());
        $id    = (int) $row['pk_i_id'];
        $input = $call->input();
        if ($level === LocationQuery::AREA) {
            $this->locations->editArea($id, AdminText::clean($input['name']));
        } else {
            $name = array_key_exists('name', $input) ? AdminText::clean($input['name']) : (string) $row['s_name'];
            $slug = array_key_exists('slug', $input) ? trim((string) $input['slug']) : (string) ($row['s_slug'] ?? '');
            $level === LocationQuery::REGION ? $this->locations->editRegion($id, $name, $slug) : $this->locations->editCity($id, $name, $slug);
        }

        return Response::ok($this->serialize($level, $this->row($level, $id)));
    }

    private function delete(ApiCall $call, string $level): Response
    {
        $this->locations->delete($level, $call->intArg());

        return Response::noContent();
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function serialize(string $level, array $row): array
    {
        return match ($level) {
            LocationQuery::REGION => $this->serializer->region($row),
            LocationQuery::CITY   => $this->serializer->city($row),
            default               => $this->serializer->area($row),
        };
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function row(string $level, int $id): array
    {
        return ProblemException::found((new LocationQuery())->find($level, $id), 'location');
    }
}
