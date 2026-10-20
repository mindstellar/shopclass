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
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\exception\RefusedException;
use mindstellar\routing\ReservedSlugs;
use mindstellar\utility\DeferredMail;
use Region;
use RegionStats;

/**
 * Adding, renaming and deleting countries, regions, cities and city areas, as Settings -> Locations and
 * the API do it, each write in one transaction. A rename keeps listings' stored names in step;
 * a delete takes what lives under the location with it, as the models do.
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
        $text  = static fn (string $key): string => (string) ($in[$key] ?? '');
        $found = (new LocationQuery())->lineage($text('countryCode'), (int) $text('regionId'), (int) $text('cityId'));
        $out   = [
            'countryId'   => $found['countryCode'],
            'countryName' => $found['countryCode'] !== null ? $found['countryName'] : $text('country'),
        ];

        $region = self::place('region', $text('regionId'), $text('region'), $found['regionName'], \Region::getInstance(), null, $out['countryId'], $matchByName);
        $out    = $out + ['regionId' => $region[0], 'regionName' => $region[1]];
        $city   = self::place('city', $text('cityId'), $text('city'), $found['cityName'], \City::getInstance(), $out['regionId'], $out['countryId'], $matchByName);

        return $out + ['cityId' => $city[0], 'cityName' => $city[1]];
    }

    /**
     * Refuse a country, region or city id that does not exist, a region outside the country or a
     * city outside the region (or the country, when no region is given). An empty value is not checked.
     *
     * @throws InvalidException `unknown` for a place that does not exist, `mismatch` for one under another parent
     */
    public static function checkPlaces(string $countryCode, string $regionId, string $cityId): void
    {
        $country = strtoupper(trim($countryCode));
        $region  = self::placeId($regionId, '/region_id');
        $city    = self::placeId($cityId, '/city_id');
        if ($country === '' && $region === 0 && $city === 0) {
            return;
        }
        $found = (new LocationQuery())->lineage($country, $region, $city);
        if ($country !== '' && $found['country'] === null) {
            throw new InvalidException('/country', 'unknown', _m('is not a country of this site'));
        }
        if ($region > 0 && $found['regionCountry'] === null) {
            throw new InvalidException('/region_id', 'unknown', _m('does not exist'));
        }
        if ($region > 0 && $country !== '' && strtoupper($found['regionCountry']) !== $country) {
            throw new InvalidException('/region_id', 'mismatch', _m('is not in that country'));
        }
        if ($city > 0 && $found['cityRegion'] === null) {
            throw new InvalidException('/city_id', 'unknown', _m('does not exist'));
        }
        if ($city > 0 && $region > 0 && $found['cityRegion'] !== $region) {
            throw new InvalidException('/city_id', 'mismatch', _m('is not in that region'));
        }
        $cityCountry = strtoupper((string) $found['cityCountry']);
        if ($city > 0 && $region === 0 && $country !== '' && $cityCountry !== '' && $cityCountry !== $country) {
            throw new InvalidException('/city_id', 'mismatch', _m('is not in that country'));
        }
    }

    /**
     * @return int the id; 0 for an empty one
     * @throws InvalidException `unknown` for a value that is not a positive id
     */
    private static function placeId(string $id, string $pointer): int
    {
        $id = trim($id);
        if ($id === '') {
            return 0;
        }
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw new InvalidException($pointer, 'unknown', _m('does not exist'));
        }

        return (int) $id;
    }

    /**
     * @param string|null $stored the name stored for the id, from lineage()
     *
     * @return array{0:?int,1:?string} the place's id and name
     */
    private static function place(string $level, string $id, string $name, ?string $stored, $model, ?int $parent, ?string $countryId, bool $matchByName): array
    {
        if ($id !== '') {
            return (int) $id > 0 && $stored !== null ? [(int) $id, $stored] : [null, null];
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
     * Add a country by its two-letter code; its slug is made from the name.
     *
     * @return string the stored code
     * @throws RefusedException
     * @throws \mindstellar\database\DbException when the row could not be written
     */
    public function addCountry(string $code, string $name): string
    {
        return (string) DeferredMail::transaction(function () use ($code, $name): string {
            $this->checkName($name, _m('Country name cannot be blank'));
            $code = CountryCode::normalize($code);
            if ($code === null) {
                throw new InvalidException('/code', 'invalid', _m('The country code must be two letters, like IN or DE'));
            }
            if (LocationStore::country($code) !== null) {
                throw new InvalidException('/code', 'taken', sprintf(_m('%s already was in the database'), $name));
            }
            LocationStore::addCountry($code, $name, '');
            osc_calculate_location_slug('country');
            osc_calculate_location_slug('region');
            osc_calculate_location_slug('city');

            return $code;
        });
    }

    /**
     * Rename a country. A slug another country holds, or none, is made from the name.
     *
     * @throws RefusedException
     * @throws \mindstellar\database\DbException when the row could not be written
     */
    public function editCountry(string $code, string $name, string $slug = ''): void
    {
        DeferredMail::transaction(function () use ($code, $name, $slug): void {
            $this->checkName($name, _m('Country name cannot be blank'));
            $country = $code === '' ? null : LocationStore::country($code);
            if ($country === null) {
                throw new NotFoundException(_m('This location no longer exists.'));
            }
            $code = (string) $country['pk_c_code'];
            LocationStore::renameCountry($code, $name, self::uniqueSlug(\Country::getInstance(), $code, $name, $slug, 'pk_c_code'));
        });
    }

    /**
     * @return int the new region's id
     * @throws RefusedException
     */
    public function addRegion(string $countryCode, string $name): int
    {
        return (int) DeferredMail::transaction(function () use ($countryCode, $name): int {
            $country = \Country::getInstance()->findByCode($countryCode);
            if (!isset($country['pk_c_code'])) {
                throw new NotFoundException(_m('This location no longer exists.'));
            }
            $regions = new Region();
            $this->checkName($name, _m('Region name cannot be blank'));
            if (isset($regions->findByName($name, $country['pk_c_code'])['s_name'])) {
                throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
            }
            $id = (int) $regions->insertGetId(['fk_c_country_code' => $country['pk_c_code'], 's_name' => $name]);
            RegionStats::getInstance()->setNumItems($id, 0);
            osc_calculate_location_slug('region');
            osc_calculate_location_slug('city');

            return $id;
        });
    }

    /**
     * Rename a region. A slug another region holds, or none, is made from the name.
     *
     * @throws RefusedException
     */
    public function editRegion(int $id, string $name, string $slug = ''): void
    {
        DeferredMail::transaction(function () use ($id, $name, $slug): void {
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
            ItemLocation::getInstance()->update(['s_region' => $name], ['fk_i_region_id' => $id]);
        });
    }

    /**
     * @return int the new city's id
     * @throws RefusedException
     */
    public function addCity(int $regionId, string $name): int
    {
        return (int) DeferredMail::transaction(function () use ($regionId, $name): int {
            $region = $regionId > 0 ? Region::getInstance()->findByPrimaryKey($regionId) : false;
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
            CityStats::getInstance()->setNumItems($id, 0);
            osc_calculate_location_slug('city');

            return $id;
        });
    }

    /**
     * Rename a city. A slug another city holds, or none, is made from the name.
     *
     * @throws RefusedException
     */
    public function editCity(int $id, string $name, string $slug = ''): void
    {
        DeferredMail::transaction(function () use ($id, $name, $slug): void {
            $cities = new City();
            $city   = $id > 0 ? $cities->findByPrimaryKey($id) : false;
            if (!is_array($city)) {
                throw new NotFoundException(_m('This location no longer exists.'));
            }
            $this->checkName($name, _m('City name cannot be blank'));
            $exists = $cities->findByName($name, isset($city['fk_i_region_id']) ? (int) $city['fk_i_region_id'] : null);
            if (isset($exists['pk_i_id']) && (int) $exists['pk_i_id'] !== $id) {
                throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
            }
            $cities->update(['s_name' => $name, 's_slug' => self::uniqueSlug($cities, $id, $name, $slug)], ['pk_i_id' => $id]);
            ItemLocation::getInstance()->update(['s_city' => $name], ['fk_i_city_id' => $id]);
        });
    }

    /**
     * @return int the new area's id
     * @throws RefusedException
     */
    public function addArea(int $cityId, string $name): int
    {
        return (int) DeferredMail::transaction(function () use ($cityId, $name): int {
            $city = $cityId > 0 ? City::getInstance()->findByPrimaryKey($cityId) : false;
            if (!is_array($city)) {
                throw new NotFoundException(_m('This location no longer exists.'));
            }
            $this->checkName($name, _m('City area name cannot be blank'));
            if (isset(CityArea::getInstance()->findByName($name, $cityId)['s_name'])) {
                throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
            }

            return LocationStore::addArea($cityId, $name);
        });
    }

    /**
     * Rename a city area, and listings' stored name with it.
     *
     * @throws RefusedException
     */
    public function editArea(int $id, string $name): void
    {
        DeferredMail::transaction(function () use ($id, $name): void {
            $area = $id > 0 ? CityArea::getInstance()->findByPrimaryKey($id) : false;
            if (!is_array($area)) {
                throw new NotFoundException(_m('This location no longer exists.'));
            }
            $this->checkName($name, _m('City area name cannot be blank'));
            $exists = CityArea::getInstance()->findByName($name, isset($area['fk_i_city_id']) ? (int) $area['fk_i_city_id'] : null);
            if (isset($exists['pk_i_id']) && (int) $exists['pk_i_id'] !== $id) {
                throw new InvalidException('/name', 'invalid', sprintf(_m('%s already was in the database'), $name));
            }
            CityArea::getInstance()->update(['s_name' => $name], ['pk_i_id' => $id]);
            ItemLocation::getInstance()->update(['s_city_area' => $name], ['fk_i_city_area_id' => $id]);
        });
    }

    /**
     * Delete a country, region, city or city area and what lives under it, all or none.
     *
     * @param string     $level 'country', 'region', 'city' or 'area'
     * @param int|string $id    the country code, or the place's id
     *
     * @throws NotFoundException
     * @throws \RuntimeException when part of it could not be deleted
     */
    public function delete(string $level, int|string $id): void
    {
        if ($level === 'country') {
            $id = CountryCode::normalize((string) $id) ?? '';
        } else {
            $id = is_int($id) || ctype_digit($id) ? (int) $id : 0;
        }
        $model = match ($level) {
            'country' => \Country::getInstance(),
            'region'  => Region::getInstance(),
            'city'    => City::getInstance(),
            'area'    => CityArea::getInstance(),
            default   => throw new \InvalidArgumentException('Unknown location level'),
        };
        if ($id === '' || $id === 0 || !(new LocationQuery())->exists($level, $id)) {
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
     * A slug for a renamed country, region or city: the typed one when no other row holds it, else
     * one made from the name, with `-1`, `-2`... added while it is taken.
     *
     * @param \Country|Region|City $model
     */
    private static function uniqueSlug($model, int|string $self, string $name, string $wanted, string $key = 'pk_i_id'): string
    {
        $takenByOther = static function (string $slug) use ($model, $key, $self): bool {
            $row = $model->findBySlug($slug);

            return isset($row['s_slug']) && (string) $row[$key] !== (string) $self;
        };

        return ReservedSlugs::unique(osc_sanitizeString($wanted === '' || $takenByOther($wanted) ? $name : $wanted), $takenByOther, '-');
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
