<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Location
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Gets current country
 *
 * @return array|string
 */
function osc_country()
{
    if (View::newInstance()->_exists('countries')) {
        return View::newInstance()->_current('countries');
    }

    return null;
}

/**
 * Gets current region
 *
 * @return array|string
 */
function osc_region()
{
    if (View::newInstance()->_exists('regions')) {
        return View::newInstance()->_current('regions');
    }

    return null;
}

/**
 * Gets current city
 *
 * @return array|string
 */
function osc_city()
{
    if (View::newInstance()->_exists('cities')) {
        return View::newInstance()->_current('cities');
    }

    return null;
}

/**
 * Gets current city area
 *
 * @return array|string
 */
function osc_city_area()
{
    if (View::newInstance()->_exists('city_areas')) {
        return View::newInstance()->_current('city_areas');
    }

    return null;
}

/**
 * Iterator for countries, return null if there's no more countries
 *
 * @return bool
 */
function osc_has_countries()
{
    if (!View::newInstance()->_exists('countries')) {
        View::newInstance()->_exportVariableToView('countries', CountryStats::newInstance()->listCountries('>='));
    }

    return View::newInstance()->_next('countries');
}

/**
 * Iterator for regions, return null if there's no more regions
 *
 * @param string $country
 *
 * @return bool
 */
function osc_has_regions($country = '%%%%')
{
    if (!View::newInstance()->_exists('regions')) {
        View::newInstance()->_exportVariableToView('regions', RegionStats::newInstance()->listRegions($country, '>='));
    }

    return View::newInstance()->_next('regions');
}

/**
 * Iterator for cities, return null if there's no more cities
 *
 * @param string $region
 *
 * @return bool
 */
function osc_has_cities($region = '%%%%')
{
    if (!View::newInstance()->_exists('cities')) {
        View::newInstance()->_exportVariableToView('cities', CityStats::newInstance()->listCities($region, '>='));
    }
    $result = View::newInstance()->_next('cities');

    if (!$result) {
        View::newInstance()->_erase('cities');
    }

    return $result;
}

/**
 * Iterator for city areas, return null if there's no more city areas
 *
 * @param string $city
 *
 * @return bool
 */
function osc_has_city_areas($city = '%%%%')
{
    if (!View::newInstance()->_exists('city_areas')) {
        View::newInstance()->_exportVariableToView(
            'city_areas',
            Search::newInstance()->listCityAreas($city, '>=', 'city_area_name ASC')
        );
    }
    $result = View::newInstance()->_next('city_areas');

    if (!$result) {
        View::newInstance()->_erase('city_areas');
    }

    return $result;
}

/**
 * Gets number of countries
 *
 * @return int
 */
function osc_count_countries()
{
    if (!View::newInstance()->_exists('contries')) {
        View::newInstance()
            ->_exportVariableToView('countries', CountryStats::newInstance()->listCountries('>=', 'country_name ASC'));
    }

    return View::newInstance()->_count('countries');
}

/**
 * Gets number of regions
 *
 * @param string $country
 *
 * @return int
 */
function osc_count_regions($country = '%%%%')
{
    if (!View::newInstance()->_exists('regions')) {
        View::newInstance()->_exportVariableToView(
            'regions',
            RegionStats::newInstance()->listRegions($country, '>=', 'region_name ASC')
        );
    }

    return View::newInstance()->_count('regions');
}

/**
 * Gets number of cities
 *
 * @param string $region
 *
 * @return int
 */
function osc_count_cities($region = '%%%%')
{
    if (!View::newInstance()->_exists('cities')) {
        View::newInstance()->_exportVariableToView('cities', CityStats::newInstance()->listCities($region, '>='));
    }

    return View::newInstance()->_count('cities');
}

/**
 * Gets number of city areas
 *
 * @param string $city
 *
 * @return int
 */
function osc_count_city_areas($city = '%%%%')
{
    if (!View::newInstance()->_exists('city_areas')) {
        View::newInstance()->_exportVariableToView(
            'city_areas',
            Search::newInstance()->listCityAreas($city, '>=', 'city_area_name ASC')
        );
    }

    return View::newInstance()->_count('city_areas');
}

/**
 * Gets country's name
 *
 * @return string
 */
function osc_country_name()
{
    return osc_field(osc_country(), 'country_name', '');
}

/**
 * Gets country's items
 *
 * @return int
 */
function osc_country_items()
{
    return osc_field(osc_country(), 'items', '');
}

/**
 * Gets region's name
 *
 * @return array|string
 */
function osc_region_name()
{
    return osc_field(osc_region(), 'region_name', '');
}

/**
 * Gets region's items
 *
 * @return int
 */
function osc_region_items()
{
    return osc_field(osc_region(), 'items', '');
}

/**
 * Gets city's name
 *
 * @return string
 */
function osc_city_name()
{
    return osc_field(osc_city(), 'city_name', '');
}

/**
 * Gets city's items
 *
 * @return int
 */
function osc_city_items()
{
    return osc_field(osc_city(), 'items', '');
}

/**
 * Gets city area's name
 *
 * @return string
 */
function osc_city_area_name()
{
    return osc_field(osc_city_area(), 'city_area_name', '');
}

/**
 * Gets city area's items
 *
 * @return int
 */
function osc_city_area_items()
{
    return osc_field(osc_city_area(), 'items', '');
}

/**
 * Gets country's url
 *
 * @return string
 */
function osc_country_url()
{
    return osc_search_url(array('sCountry' => osc_country_name()));
}

/**
 * Gets region's url
 *
 * @return string
 */
function osc_region_url()
{
    return osc_search_url(array('sRegion' => osc_region_name()));
}

/**
 * Gets city's url
 *
 * @return string
 */
function osc_city_url()
{
    return osc_search_url(array('sCity' => osc_city_name()));
}

/**
 * Gets city area's url
 *
 * @return string
 */
function osc_city_area_url()
{
    return osc_search_url(array('sCityArea' => osc_city_area_name()));
}

/**
 * Install or update one country's locations from the published catalog.
 *
 * Kept as a thin wrapper over {@see \mindstellar\location\LocationImporter} so existing
 * callers keep their signature and boolean return. The importer is what makes a second
 * call an update rather than a duplication: it matches on the upstream source id and
 * renames in place, where this used to skip anything whose name already existed and so
 * could never refresh a row.
 *
 * @param string $location the catalog file name, e.g. "IN-India.json"
 *
 * @return bool
 */
function osc_install_json_locations($location = null)
{
    if ($location === null || $location === '') {
        return false;
    }

    $catalog = new \mindstellar\location\LocationCatalog();

    // A country is named either by its code or by the published file name. The code is
    // what the admin and installer send, because it survives the catalog rearranging its
    // files; the file name is what older callers pass and still works. Going through the
    // catalog row rather than straight to countryFile() is what lets the import stream
    // the country where the catalog offers a streamable copy of it.
    $entry = null;
    foreach ($catalog->status() as $row) {
        if ($row['file'] === (string) $location || strcasecmp($row['code'], (string) $location) === 0) {
            $entry = $row;
            break;
        }
    }

    if ($entry === null) {
        // Not in the manifest — a hand-supplied file name. Import it the old way rather
        // than refusing, since that is all this function could ever do before.
        $data = $catalog->countryFile((string) $location);
        if ($data === null) {
            return false;
        }
        $report = (new \mindstellar\location\LocationImporter())->import($data);

        return !isset($report['error']);
    }

    $report = (new \mindstellar\location\LocationImporter())->importCountry($catalog, $entry);
    if (isset($report['error'])) {
        return false;
    }

    // Record which published version this install now holds, so the admin can be told
    // when it goes stale without downloading anything to find out.
    if ($entry['sha'] !== '') {
        $catalog->markInstalled((string) $entry['code'], (string) $entry['sha']);
    }

    return true;
}
