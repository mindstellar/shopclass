<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
 * @return array<string,mixed>|null Null when no countries have been loaded
 */
function osc_country()
{
    if (View::getInstance()->_exists('countries')) {
        return View::getInstance()->_current('countries');
    }

    return null;
}

/**
 * Gets current region
 *
 * @return array<string,mixed>|null Null when no regions have been loaded
 */
function osc_region()
{
    if (View::getInstance()->_exists('regions')) {
        return View::getInstance()->_current('regions');
    }

    return null;
}

/**
 * Gets current city
 *
 * @return array<string,mixed>|null Null when no cities have been loaded
 */
function osc_city()
{
    if (View::getInstance()->_exists('cities')) {
        return View::getInstance()->_current('cities');
    }

    return null;
}

/**
 * Gets current city area
 *
 * @return array<string,mixed>|null Null when no city areas have been loaded
 */
function osc_city_area()
{
    if (View::getInstance()->_exists('city_areas')) {
        return View::getInstance()->_current('city_areas');
    }

    return null;
}

/**
 * Place rows shown in the current locale's names; rows with no translation keep the stored name.
 *
 * @param mixed $rows a list of place rows; anything else is returned as it is
 *
 * @return mixed
 */
function _osc_location_translate(mixed $rows, string $type, string $idKey, string $nameKey): mixed
{
    if (!is_array($rows) || $rows === []) {
        return $rows;
    }
    $locale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();

    return \mindstellar\container\Container::root()->get(\mindstellar\location\LocationNames::class)
        ->translateList($rows, $type, $idKey, $nameKey, $locale);
}

/**
 * A country, region or city table list (pk_c_code or pk_i_id, s_name) in the current locale's names.
 *
 * @param mixed  $rows a list of place rows; anything else is returned as it is
 * @param string $type 'country', 'region' or 'city'
 *
 * @return mixed
 */
function _osc_location_list(mixed $rows, string $type): mixed
{
    return _osc_location_translate($rows, $type, $type === 'country' ? 'pk_c_code' : 'pk_i_id', 's_name');
}

/**
 * Iterator for countries, return null if there's no more countries
 *
 * @return bool
 */
function osc_has_countries()
{
    if (!View::getInstance()->_exists('countries')) {
        View::getInstance()->_exportVariableToView('countries', _osc_location_translate(CountryStats::getInstance()->listCountries('>='), 'country', 'country_code', 'country_name'));
    }

    return View::getInstance()->_next('countries');
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
    if (!View::getInstance()->_exists('regions')) {
        View::getInstance()->_exportVariableToView('regions', _osc_location_translate(RegionStats::getInstance()->listRegions($country, '>='), 'region', 'region_id', 'region_name'));
    }

    return View::getInstance()->_next('regions');
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
    if (!View::getInstance()->_exists('cities')) {
        View::getInstance()->_exportVariableToView('cities', _osc_location_translate(CityStats::getInstance()->listCities($region, '>='), 'city', 'city_id', 'city_name'));
    }
    $result = View::getInstance()->_next('cities');

    if (!$result) {
        View::getInstance()->_erase('cities');
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
    if (!View::getInstance()->_exists('city_areas')) {
        View::getInstance()->_exportVariableToView(
            'city_areas',
            Search::getInstance()->listCityAreas($city, '>=', 'city_area_name ASC')
        );
    }
    $result = View::getInstance()->_next('city_areas');

    if (!$result) {
        View::getInstance()->_erase('city_areas');
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
    if (!View::getInstance()->_exists('countries')) {
        View::getInstance()
            ->_exportVariableToView('countries', CountryStats::getInstance()->listCountries('>=', 'country_name ASC'));
    }

    return View::getInstance()->_count('countries');
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
    if (!View::getInstance()->_exists('regions')) {
        View::getInstance()->_exportVariableToView(
            'regions',
            RegionStats::getInstance()->listRegions($country, '>=', 'region_name ASC')
        );
    }

    return View::getInstance()->_count('regions');
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
    if (!View::getInstance()->_exists('cities')) {
        View::getInstance()->_exportVariableToView('cities', _osc_location_translate(CityStats::getInstance()->listCities($region, '>='), 'city', 'city_id', 'city_name'));
    }

    return View::getInstance()->_count('cities');
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
    if (!View::getInstance()->_exists('city_areas')) {
        View::getInstance()->_exportVariableToView(
            'city_areas',
            Search::getInstance()->listCityAreas($city, '>=', 'city_area_name ASC')
        );
    }

    return View::getInstance()->_count('city_areas');
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
 * @return int|string
 */
function osc_country_items()
{
    return osc_field(osc_country(), 'items', '');
}

/**
 * Gets region's name
 *
 * @return string
 */
function osc_region_name()
{
    return osc_field(osc_region(), 'region_name', '');
}

/**
 * Gets region's items
 *
 * @return int|string
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
 * @return int|string
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
 * @return int|string
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
 * @param string|null $location the catalog file name, e.g. "IN-India.json", or a country code
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

/**
 * The coordinates of an address through the geocode provider the owner picked, cached.
 *
 * @param string      $address
 * @param string|null $countryCode two-letter code that narrows the search
 *
 * @return array{0:float,1:float}|null latitude and longitude; null with no provider or no match
 * @since 7.0.0
 */
function osc_geocode(string $address, ?string $countryCode = null): ?array
{
    $geocoder = \mindstellar\location\Geocoder::active();
    if ($geocoder === null) {
        return null;
    }
    try {
        return $geocoder->geocode($address, $countryCode);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * The credit the active geocode provider requires near a map or results, or ''. Echo it as
 * it is: it is the plugin's own markup, and escaping it would break the required link.
 *
 * @return string markup from the provider plugin
 * @since 7.0.0
 */
function osc_geocode_attribution(): string
{
    $geocoder = \mindstellar\location\Geocoder::active();

    return $geocoder === null ? '' : $geocoder->provider()->attribution();
}

/**
 * The name of a country, region or city in the current language, or $fallback when none is stored.
 *
 * @param string     $type     'country', 'region' or 'city'
 * @param int|string $id       region or city id, or country code
 * @param string     $fallback returned when the place has no name in this language
 *
 * @return string
 * @since 7.1.0
 */
function osc_location_name(string $type, int|string $id, string $fallback = ''): string
{
    $locale = OC_ADMIN ? osc_current_admin_locale() : osc_current_user_locale();

    return \mindstellar\container\Container::root()->get(\mindstellar\location\LocationNames::class)->name($type, $id, $locale, $fallback);
}
