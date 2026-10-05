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

namespace mindstellar\location;

use City;
use CityArea;
use CityStats;
use ItemLocation;
use mindstellar\utility\DeferredMail;
use mindstellar\validation\InvalidException;
use mindstellar\validation\NotFoundException;
use mindstellar\validation\RefusedException;
use Region;
use RegionStats;

/**
 * Adding, renaming and deleting regions, cities and city areas, as Settings -> Locations and
 * the API do it. A rename keeps listings' stored names in step; a delete takes what lives
 * under the location with it, as the models do.
 */
final class LocationService
{
    /**
     * Turn a posted country, region and city into stored ids and names. An id that exists wins,
     * an empty one falls back to the free text (and, with $matchByName, to a place of that
     * name). An id that is posted but not found gives null for the id and the name.
     *
     * @param array<string,mixed> $in countryCode, country, regionId, region, cityId, city
     *
     * @return array{countryId:?string,countryName:?string,regionId:?int,regionName:?string,cityId:?int,cityName:?string}
     */
    public static function resolve(array $in, bool $matchByName = false): array
    {
        $text    = static fn (string $key): string => (string) ($in[$key] ?? '');
        $country = \Country::newInstance()->findByCode($text('countryCode'));
        $found   = is_array($country) && !empty($country);
        $out     = [
            'countryId'   => $found ? $country['pk_c_code'] : null,
            'countryName' => $found ? $country['s_name'] : $text('country'),
        ];

        $regionId = self::place('region', $text('regionId'), $text('region'), \Region::newInstance(), null, $out['countryId'], $matchByName);
        $out      = $out + ['regionId' => $regionId[0], 'regionName' => $regionId[1]];
        $city     = self::place('city', $text('cityId'), $text('city'), \City::newInstance(), $out['regionId'], $out['countryId'], $matchByName);

        return $out + ['cityId' => $city[0], 'cityName' => $city[1]];
    }

    /**
     * @return array{0:?int,1:?string} the place's id and name
     */
    private static function place(string $level, string $id, string $name, $model, ?int $parent, ?string $countryId, bool $matchByName): array
    {
        if ($id !== '') {
            $place = (int) $id > 0 ? $model->findByPrimaryKey((int) $id) : null;

            return is_array($place) && !empty($place) ? [(int) $place['pk_i_id'], $place['s_name']] : [null, null];
        }
        if ($matchByName && $countryId !== null) {
            $place = $model->findByName($name, $level === 'region' ? $countryId : $parent);
            if (is_array($place) && !empty($place)) {
                return [(int) $place['pk_i_id'], $place['s_name']];
            }
        }

        return [null, $name];
    }

    /**
     * @return int the new region's id
     * @throws RefusedException
     */
    public function addRegion(string $countryCode, string $name): int
    {
        $country = \Country::newInstance()->findByCode($countryCode);
        if (!isset($country['pk_c_code'])) {
            throw new NotFoundException(_m('This location no longer exists.'));
        }
        $regions = new Region();
        $this->checkName($name, _m('Region name cannot be blank'));
        if (isset($regions->findByName($name, $country['pk_c_code'])['s_name'])) {
            throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
        }
        $id = (int) $regions->insertGetId(['fk_c_country_code' => $country['pk_c_code'], 's_name' => $name]);
        RegionStats::newInstance()->setNumItems($id, 0);
        osc_calculate_location_slug('region');
        osc_calculate_location_slug('city');

        return $id;
    }

    /**
     * Rename a region. A slug another region holds, or none, is made from the name.
     *
     * @throws RefusedException
     */
    public function editRegion(int $id, string $name, string $slug = ''): void
    {
        $regions = new Region();
        $region  = $id > 0 ? $regions->findByPrimaryKey($id) : false;
        if (!is_array($region)) {
            throw new NotFoundException(_m('This location no longer exists.'));
        }
        $this->checkName($name, _m('Region name cannot be blank'));
        $exists = $regions->findByName($name, $region['fk_c_country_code']);
        if (isset($exists['pk_i_id']) && (int) $exists['pk_i_id'] !== $id) {
            throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
        }
        $regions->update(['s_name' => $name, 's_slug' => self::uniqueSlug($regions, $id, $name, $slug)], ['pk_i_id' => $id]);
        ItemLocation::newInstance()->update(['s_region' => $name], ['fk_i_region_id' => $id]);
    }

    /**
     * @return int the new city's id
     * @throws RefusedException
     */
    public function addCity(int $regionId, string $name): int
    {
        $region = $regionId > 0 ? Region::newInstance()->findByPrimaryKey($regionId) : false;
        if (!is_array($region)) {
            throw new NotFoundException(_m('This location no longer exists.'));
        }
        $cities = new City();
        $this->checkName($name, _m('New city name cannot be blank'));
        if (isset($cities->findByName($name, $regionId)['s_name'])) {
            throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
        }
        // The region's country, not a posted one: a city stored under another country's code
        // falls out of that country's listings.
        $id = (int) $cities->insertGetId([
            'fk_i_region_id'    => $regionId,
            's_name'            => $name,
            'fk_c_country_code' => $region['fk_c_country_code'],
        ]);
        CityStats::newInstance()->setNumItems($id, 0);
        osc_calculate_location_slug('city');

        return $id;
    }

    /**
     * Rename a city. A slug another city holds, or none, is made from the name.
     *
     * @throws RefusedException
     */
    public function editCity(int $id, string $name, string $slug = ''): void
    {
        $cities = new City();
        $city   = $id > 0 ? $cities->findByPrimaryKey($id) : false;
        if (!is_array($city)) {
            throw new NotFoundException(_m('This location no longer exists.'));
        }
        $this->checkName($name, _m('City name cannot be blank'));
        $exists = $cities->findByName($name, $city['fk_i_region_id']);
        if (isset($exists['pk_i_id']) && (int) $exists['pk_i_id'] !== $id) {
            throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
        }
        $cities->update(['s_name' => $name, 's_slug' => self::uniqueSlug($cities, $id, $name, $slug)], ['pk_i_id' => $id]);
        ItemLocation::newInstance()->update(['s_city' => $name], ['fk_i_city_id' => $id]);
    }

    /**
     * @return int the new area's id
     * @throws RefusedException
     */
    public function addArea(int $cityId, string $name): int
    {
        $city = $cityId > 0 ? City::newInstance()->findByPrimaryKey($cityId) : false;
        if (!is_array($city)) {
            throw new NotFoundException(_m('This location no longer exists.'));
        }
        $this->checkName($name, _m('City area name cannot be blank'));
        if (isset(CityArea::newInstance()->findByName($name, $cityId)['s_name'])) {
            throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
        }

        return (int) osc_db_table(DB_TABLE_PREFIX . 't_city_area')->insert(['fk_i_city_id' => $cityId, 's_name' => $name]);
    }

    /**
     * Rename a city area, and listings' stored name with it.
     *
     * @throws RefusedException
     */
    public function editArea(int $id, string $name): void
    {
        $area = $id > 0 ? CityArea::newInstance()->findByPrimaryKey($id) : false;
        if (!is_array($area)) {
            throw new NotFoundException(_m('This location no longer exists.'));
        }
        $this->checkName($name, _m('City area name cannot be blank'));
        $exists = CityArea::newInstance()->findByName($name, $area['fk_i_city_id']);
        if (isset($exists['pk_i_id']) && (int) $exists['pk_i_id'] !== $id) {
            throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
        }
        CityArea::newInstance()->update(['s_name' => $name], ['pk_i_id' => $id]);
        ItemLocation::newInstance()->update(['s_city_area' => $name], ['fk_i_city_area_id' => $id]);
    }

    /**
     * Delete a region, city or city area and what lives under it, all or none.
     *
     * @param string $level 'region', 'city' or 'area'
     *
     * @throws NotFoundException
     * @throws \RuntimeException when part of it could not be deleted
     */
    public function delete(string $level, int $id): void
    {
        [$table, $model] = match ($level) {
            'region' => ['t_region', Region::newInstance()],
            'city'   => ['t_city', City::newInstance()],
            'area'   => ['t_city_area', CityArea::newInstance()],
        };
        if ($id <= 0 || osc_db_table(DB_TABLE_PREFIX . $table)->where('pk_i_id', $id)->first() === null) {
            throw new NotFoundException(_m('This location no longer exists.'));
        }
        DeferredMail::transaction(static function () use ($model, $id): void {
            // The models answer the number of deletes that failed.
            if ((int) $model->deleteByPrimaryKey($id) !== 0) {
                throw new \RuntimeException('The location could not be deleted.');
            }
        });
    }

    /**
     * A slug for a renamed region or city: the typed one when no other row holds it, else
     * one made from the name, with `-1`, `-2`... added while it is taken.
     *
     * @param Region|City $model
     */
    public static function uniqueSlug($model, int|string $self, string $name, string $wanted, string $key = 'pk_i_id'): string
    {
        $takenByOther = static function (string $slug) use ($model, $key, $self): bool {
            $row = $model->findBySlug($slug);

            return isset($row['s_slug']) && (string) $row[$key] !== (string) $self;
        };

        $base = osc_sanitizeString($wanted === '' || $takenByOther($wanted) ? $name : $wanted);
        $slug = $base;
        for ($n = 1; $takenByOther($slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * @throws InvalidException when the name is blank
     */
    private function checkName(string $name, string $blank): void
    {
        if (!osc_validate_min($name, 1)) {
            throw new InvalidException('/name', 'invalid', $blank);
        }
    }
}
