<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

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

use mindstellar\location\LocationAdminQuery;

/**
 * Class CAdminSettingsLocations
 */
class CAdminSettingsLocations extends AdminSecBaseModel
{
    /** Forms the screen can show, as ?form=… */
    private const FORMS = array('add', 'edit', 'delete', 'import');

    /**
     * Boots the admin controller and fires the init_admin_settings_locations hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_locations');
    }

    //Business Layer...

    /**
     * Routes the location actions: add/edit/delete for countries, regions and cities, plus the importer.
     * Without a write it renders the list, or only its list or form partial for `partial=list|form`.
     *
     * @return void
     * @throws \Exception
     */
    public function doModel()
    {
        switch (Params::getParamString('type')) {
            case ('add_country'):
                $this->guard();
                $this->addCountry();
                break;
            case ('edit_country'):
                $this->guard();
                $this->editCountry();
                break;
            case ('delete_country'):
                $this->guard();
                $this->deleteLocations('country');
                break;
            case ('add_region'):
                $this->guard();
                $this->addRegion();
                break;
            case ('edit_region'):
                $this->guard();
                $this->editRegion();
                break;
            case ('delete_region'):
                $this->guard();
                $this->deleteLocations('region');
                break;
            case ('add_city'):
                $this->guard();
                $this->addCity();
                break;
            case ('edit_city'):
                $this->guard();
                $this->editCity();
                break;
            case ('delete_city'):
                $this->guard();
                $this->deleteLocations('city');
                break;
            case ('locations_import'):
                $this->guard();
                $this->importLocation();
                break;
        }

        $partial = Params::getParamString('partial');

        // Old deep links named the country twice (country_code=IN&country=India).
        if ($partial === '' && Params::getParamString('country_code') !== '') {
            $this->redirectTo($this->listUrl(array(
                'country' => Params::getParamString('country_code'),
                'region'  => Params::getParamInt('region'),
                'pageNum' => Params::getParamInt('pageNum'),
            )));
        }

        $list = $this->listModel();
        $this->_exportVariableToView('locations', $list);
        $form = $this->formModel($list);
        $this->_exportVariableToView('locationForm', $form);

        if (!$list['found']) {
            http_response_code(404);
        }

        if ($partial === 'list' || $partial === 'form') {
            // An empty form partial is not marked, so the script falls back to a full page load.
            if ($partial === 'list' || $form !== null) {
                header('X-Osc-Partial: ' . $partial);
            }
            header('Cache-Control: no-store');
            osc_current_admin_theme_path('settings/locations/' . $partial . '.php');

            return;
        }

        $this->doView('settings/locations.php');
    }

    /**
     * The level on screen: its parent path, one page of rows and the totals.
     *
     * @return array<string,mixed>
     * @throws \mindstellar\database\DbException
     */
    private function listModel(): array
    {
        $query    = new LocationAdminQuery();
        $page     = max(1, Params::getParamInt('pageNum', 1));
        $per      = LocationAdminQuery::DEFAULT_PER;
        $regionId = Params::getParamInt('region');
        $country  = strtoupper(trim(Params::getParamString('country_code') ?: Params::getParamString('country')));

        $model = array(
            'base'    => osc_admin_base_url(true) . '?page=settings&action=locations',
            'level'   => 'country',
            'found'   => true,
            'missing' => null,
            'country' => null,
            'region'  => null,
        );

        if ($regionId > 0) {
            $model['level'] = 'city';
            $fetch          = static fn (int $p): array => $query->cities($regionId, '', $p, $per);
        } elseif ($country !== '') {
            $model['level'] = 'region';
            $fetch          = static fn (int $p): array => $query->regions($country, '', $p, $per);
        } else {
            $fetch = static fn (int $p): array => $query->countries('', $p, $per);
        }

        $result = $fetch($page);
        // A page past the end (after a delete, or a stale link) shows the last page instead.
        if ($result['rows'] === array() && $result['total'] > 0 && $page > 1) {
            $result = $fetch((int) ceil($result['total'] / $per));
        }

        if ($model['level'] !== 'country') {
            $parent = $result['parent'];
            if ($parent === null) {
                $model['found']   = false;
                $model['missing'] = $model['level'] === 'city'
                    ? array('level' => 'region', 'id' => (string) $regionId)
                    : array('level' => 'country', 'id' => $country);
            } elseif ($model['level'] === 'city') {
                $model['region']  = array('id' => (int) $parent['id'], 'name' => (string) $parent['name']);
                $model['country'] = $parent['country'] === null ? null : array(
                    'code' => (string) $parent['country']['code'],
                    'name' => (string) ($parent['country']['name'] ?? $parent['country']['code']),
                );
            } else {
                $model['country'] = array('code' => (string) $parent['id'], 'name' => (string) $parent['name']);
            }
        }

        return $model + array(
            'rows'  => $result['rows'],
            'total' => $result['total'],
            'page'  => $result['page'],
            'per'   => $result['per'],
        );
    }

    /**
     * The add/edit/delete/import form asked for with ?form=…, or null.
     *
     * @param array<string,mixed> $list the list model the form belongs to
     *
     * @return array<string,mixed>|null
     * @throws \mindstellar\database\DbException
     */
    private function formModel(array $list): ?array
    {
        $kind = Params::getParamString('form');
        if (!in_array($kind, self::FORMS, true) || !$list['found']) {
            return null;
        }

        $level = $list['level'];
        $form  = array('kind' => $kind, 'level' => $level, 'error' => null);
        $query = new LocationAdminQuery();

        switch ($kind) {
            case 'edit':
                $record = $query->record($level, Params::getParamString('id', false, false, false), false);
                if ($record === null) {
                    $form['error'] = __('This location no longer exists.');
                }
                $form['record'] = $record;
                break;
            case 'delete':
                $ids = Params::getParamArray('id', false, false, false);
                if ($ids === array() && Params::getParamString('id', false, false, false) !== '') {
                    $ids = array(Params::getParamString('id', false, false, false));
                }
                $form['ids'] = array();
                if ($ids === array()) {
                    $form['error'] = __('Select at least one location to delete.');
                    break;
                }
                try {
                    $impact = $query->impact($level, $ids);
                } catch (InvalidArgumentException $e) {
                    $form['error'] = $e->getCode() === LocationAdminQuery::ERR_TOO_MANY
                        ? __('Too many locations selected')
                        : __('Invalid location id');
                    break;
                }
                if ($impact['found'] < $impact['requested']) {
                    $form['error'] = __('Some of the selected locations no longer exist');
                    break;
                }
                $form['impact'] = $impact;
                $form['ids']    = array_values(array_unique(array_map(
                    static fn ($id): string => $level === 'country' ? strtoupper(trim((string) $id)) : (string) (int) $id,
                    $ids
                )));
                $form['record'] = count($form['ids']) === 1 ? $query->record($level, $form['ids'][0], false) : null;
                break;
            case 'import':
                $form['catalog'] = array();
                try {
                    foreach ((new \mindstellar\location\LocationCatalog())->status() as $row) {
                        if (($row['file'] === '' && $row['ndjson'] === '') || ($row['installed'] && $row['current'])) {
                            continue;
                        }
                        $form['catalog'][] = $row;
                    }
                } catch (Throwable $e) {
                    $form['catalog'] = array();
                }
                break;
        }

        return $form;
    }

    /**
     * Demo sites refuse every write; every write needs a valid CSRF token.
     *
     * @return void
     */
    private function guard(): void
    {
        if (defined('DEMO')) {
            $this->respond('warning', _m("This action can't be done because it's a demo site"), $this->listUrl());
        }
        // A fetch() caller reads the CSRF refusal as JSON rather than following a redirect.
        if ($this->isXhr() && !defined('IS_AJAX')) {
            define('IS_AJAX', true);
        }
        osc_csrf_check();
    }

    /**
     * @return void
     */
    private function addCountry(): void
    {
        $mCountries  = new Country();
        $countryCode = strtoupper(trim(Params::getParamString('c_country')));
        $countryName = trim(Params::getParamString('country'));

        if (!osc_validate_min($countryName, 1)) {
            $this->respond('error', _m('Country name cannot be blank'), $this->listUrl());
        }
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            $this->respond('error', _m('The country code must be two letters, like IN or DE'), $this->listUrl());
        }

        $exists = $mCountries->findByCode($countryCode);
        if (isset($exists['s_name'])) {
            $this->respond('error', sprintf(_m('%s already was in the database'), $countryName), $this->listUrl());
        }

        if ($mCountries->insert(array('pk_c_code' => $countryCode, 's_name' => $countryName)) === false) {
            $this->respond('error', _m('There were some problems adding the country'), $this->listUrl());
        }
        osc_calculate_location_slug('country');
        osc_calculate_location_slug('region');
        osc_calculate_location_slug('city');
        $this->respond('ok', sprintf(_m('%s has been added as a new country'), $countryName), $this->listUrl());
    }

    /**
     * @return void
     */
    private function editCountry(): void
    {
        $mCountries = new Country();
        $code       = Params::getParamString('country_code');
        $name       = Params::getParamString('e_country');
        $back       = $this->listUrl(array('pageNum' => Params::getParamInt('pageNum')));

        if (!osc_validate_min($name, 1)) {
            $this->respond('error', _m('Country name cannot be blank'), $back);
        }
        if (!isset($mCountries->findByCode($code)['pk_c_code'])) {
            $this->respond('error', _m('There were some problems editing the country'), $back);
        }

        $slug = $this->uniqueSlug($mCountries, 'pk_c_code', $code, $name, Params::getParamString('e_country_slug'));
        $ok   = $mCountries->update(array('s_name' => $name, 's_slug' => $slug), array('pk_c_code' => $code));

        // Affected rows, false only on error: re-saving a country with the
        // same name changes nothing and must not read as a failure.
        if ($ok === false) {
            $this->respond('error', _m('There were some problems editing the country'), $back);
        }
        $this->respond('ok', _m('Country has been edited'), $back);
    }

    /**
     * @return void
     */
    private function addRegion(): void
    {
        $mRegions    = new Region();
        $regionName  = Params::getParamString('region');
        $countryCode = Params::getParamString('country_c_parent');
        $country     = Country::newInstance()->findByCode($countryCode);

        if (!isset($country['pk_c_code'])) {
            $this->respond('error', _m('This location no longer exists.'), $this->listUrl());
        }
        $back = $this->listUrl(array('country' => $country['pk_c_code'], 'pageNum' => Params::getParamInt('pageNum')));

        if (!osc_validate_min($regionName, 1)) {
            $this->respond('error', _m('Region name cannot be blank'), $back);
        }
        if (isset($mRegions->findByName($regionName, $countryCode)['s_name'])) {
            $this->respond('error', sprintf(_m('%s already was in the database'), $regionName), $back);
        }

        $id = $mRegions->insertGetId(array(
            'fk_c_country_code' => $country['pk_c_code'],
            's_name'            => $regionName,
        ));
        RegionStats::newInstance()->setNumItems($id, 0);
        osc_calculate_location_slug('region');
        osc_calculate_location_slug('city');
        $this->respond('ok', sprintf(_m('%s has been added as a new region'), $regionName), $back);
    }

    /**
     * @return void
     */
    private function editRegion(): void
    {
        $mRegions  = new Region();
        $newRegion = Params::getParamString('e_region');
        $regionId  = Params::getParamInt('region_id');
        $aRegion   = $regionId > 0 ? $mRegions->findByPrimaryKey($regionId) : false;

        if (!is_array($aRegion)) {
            $this->respond('error', _m('This location no longer exists.'), $this->listUrl());
        }
        $back = $this->listUrl(array(
            'country' => $aRegion['fk_c_country_code'],
            'pageNum' => Params::getParamInt('pageNum'),
        ));

        if (!osc_validate_min($newRegion, 1)) {
            $this->respond('error', _m('Region name cannot be blank'), $back);
        }
        $exists = $mRegions->findByName($newRegion, $aRegion['fk_c_country_code']);
        if (isset($exists['pk_i_id']) && (int) $exists['pk_i_id'] !== $regionId) {
            $this->respond('error', sprintf(_m('%s already was in the database'), $newRegion), $back);
        }

        $slug = $this->uniqueSlug($mRegions, 'pk_i_id', $regionId, $newRegion, Params::getParamString('e_region_slug'));
        $mRegions->update(array('s_name' => $newRegion, 's_slug' => $slug), array('pk_i_id' => $regionId));
        ItemLocation::newInstance()->update(array('s_region' => $newRegion), array('fk_i_region_id' => $regionId));
        $this->respond('ok', sprintf(_m('%s has been edited'), $newRegion), $back);
    }

    /**
     * @return void
     */
    private function addCity(): void
    {
        $regionId = Params::getParamInt('region_parent');
        $region   = $regionId > 0 ? Region::newInstance()->findByPrimaryKey($regionId) : false;
        $mCities  = new City();
        $newCity  = Params::getParamString('city');

        if (!is_array($region)) {
            $this->respond('error', _m('This location no longer exists.'), $this->listUrl());
        }
        $back = $this->listUrl(array(
            'country' => $region['fk_c_country_code'],
            'region'  => $regionId,
            'pageNum' => Params::getParamInt('pageNum'),
        ));

        if (!osc_validate_min($newCity, 1)) {
            $this->respond('error', _m('New city name cannot be blank'), $back);
        }
        if (isset($mCities->findByName($newCity, $regionId)['s_name'])) {
            $this->respond('error', sprintf(_m('%s already was in the database'), $newCity), $back);
        }

        // The region's country, not the posted one: a city stored under another country's code
        // falls out of that country's listings.
        $id = $mCities->insertGetId(array(
            'fk_i_region_id'    => $regionId,
            's_name'            => $newCity,
            'fk_c_country_code' => $region['fk_c_country_code'],
        ));
        CityStats::newInstance()->setNumItems($id, 0);
        osc_calculate_location_slug('city');
        $this->respond('ok', sprintf(_m('%s has been added as a new city'), $newCity), $back);
    }

    /**
     * @return void
     */
    private function editCity(): void
    {
        $mCities = new City();
        $newCity = Params::getParamString('e_city');
        $cityId  = Params::getParamInt('city_id');
        $city    = $cityId > 0 ? $mCities->findByPrimaryKey($cityId) : false;

        if (!is_array($city)) {
            $this->respond('error', _m('This location no longer exists.'), $this->listUrl());
        }
        $region = Region::newInstance()->findByPrimaryKey($city['fk_i_region_id']);
        $back   = $this->listUrl(array(
            'country' => is_array($region) ? $region['fk_c_country_code'] : '',
            'region'  => (int) $city['fk_i_region_id'],
            'pageNum' => Params::getParamInt('pageNum'),
        ));

        if (!osc_validate_min($newCity, 1)) {
            $this->respond('error', _m('City name cannot be blank'), $back);
        }
        $exists = $mCities->findByName($newCity, $city['fk_i_region_id']);
        if (isset($exists['pk_i_id']) && (int) $exists['pk_i_id'] !== $cityId) {
            $this->respond('error', sprintf(_m('%s already was in the database'), $newCity), $back);
        }

        $slug = $this->uniqueSlug($mCities, 'pk_i_id', $cityId, $newCity, Params::getParamString('e_city_slug'));
        $mCities->update(array('s_name' => $newCity, 's_slug' => $slug), array('pk_i_id' => $cityId));
        ItemLocation::newInstance()->update(array('s_city' => $newCity), array('fk_i_city_id' => $cityId));
        $this->respond('ok', sprintf(_m('%s has been edited'), $newCity), $back);
    }

    /**
     * Delete the posted id[] at one level; each model cascades to what lives under it.
     *
     * @param string $level country|region|city
     *
     * @return void
     */
    private function deleteLocations(string $level): void
    {
        $posted = Params::getParamArray('id');
        if ($posted === array() && Params::getParamString('id') !== '') {
            $posted = array(Params::getParamString('id'));
        }
        $posted = array_values(array_filter(
            array_map(static fn ($id): string => is_string($id) ? trim($id) : '', $posted),
            static fn (string $id): bool => $id !== ''
        ));

        switch ($level) {
            case 'country':
                $model = new Country();
                $none  = _m('No country was selected');
                break;
            case 'region':
                $model = new Region();
                $none  = _m('No region was selected');
                break;
            default:
                $model = new City();
                $none  = _m('No city was selected');
                break;
        }

        // Only well-formed ids of rows that still exist are deleted or counted.
        $rows = array();
        foreach ($posted as $id) {
            if ($level === 'country') {
                $code = strtoupper($id);
                $row  = preg_match('/^[A-Z]{2}$/', $code) === 1 ? $model->findByCode($code) : array();
                if (isset($row['pk_c_code'])) {
                    $rows[$code] = $row;
                }
            } elseif (ctype_digit($id) && (int) $id > 0) {
                $row = $model->findByPrimaryKey((int) $id);
                if (is_array($row)) {
                    $rows[(int) $id] = $row;
                }
            }
        }

        // The list the delete form was opened from; older callers post no parent, so derive it.
        $country = strtoupper(Params::getParamString('country'));
        $region  = Params::getParamInt('region');
        if ($country === '' && $region === 0 && $rows !== array() && $level !== 'country') {
            $first   = reset($rows);
            $parent  = $level === 'city' ? Region::newInstance()->findByPrimaryKey($first['fk_i_region_id']) : $first;
            $country = is_array($parent) ? (string) $parent['fk_c_country_code'] : '';
            $region  = $level === 'city' ? (int) $first['fk_i_region_id'] : 0;
        }
        $back = $this->listUrl(array(
            'country' => $level === 'country' ? '' : $country,
            'region'  => $level === 'city' ? $region : 0,
            'pageNum' => Params::getParamInt('pageNum'),
        ));

        if ($posted === array()) {
            $this->respond('error', $none, $back);
        }
        if ($rows === array()) {
            $this->respond('error', _m('This location no longer exists.'), $back);
        }

        $deleted = 0;
        $failed  = 0;
        foreach (array_keys($rows) as $id) {
            if ($model->deleteByPrimaryKey($id) === 0) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->respond('error', _m('There was a problem deleting locations'), $back);
        }
        $this->respond(
            'ok',
            sprintf(_n('One location has been deleted', '%s locations have been deleted', $deleted), $deleted),
            $back
        );
    }

    /**
     * @return void
     */
    private function importLocation(): void
    {
        $location = Params::getParamString('location');
        if ($location !== '' && osc_install_json_locations($location) === true) {
            $this->respond('ok', _m('Location imported successfully'), $this->listUrl());
        }
        $this->respond('error', _m('There was a problem importing the selected location'), $this->listUrl());
    }

    /**
     * A slug for the row: the posted one, or one derived from the name when blank or taken
     * by another row, suffixed -1, -2… until no other row holds it.
     *
     * @param Country|Region|City $model
     * @param string              $pkField pk_c_code|pk_i_id
     * @param string|int          $self    this row's key
     * @param string              $name
     * @param string              $posted
     *
     * @return string
     */
    private function uniqueSlug($model, string $pkField, $self, string $name, string $posted): string
    {
        $takenByOther = static function (string $slug) use ($model, $pkField, $self): bool {
            $row = $model->findBySlug($slug);

            return isset($row['s_slug']) && (string) $row[$pkField] !== (string) $self;
        };

        $base = osc_sanitizeString($posted === '' || $takenByOther($posted) ? $name : $posted);
        $slug = $base;
        for ($n = 1; $takenByOther($slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * Finish a write: JSON for a fetch() caller, otherwise a flash message and a redirect.
     *
     * @param string $status   ok|error|warning
     * @param string $message
     * @param string $redirect the list the write belongs to
     *
     * @return never
     */
    private function respond(string $status, string $message, string $redirect)
    {
        if ($this->isXhr()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(array('ok' => $status === 'ok', 'message' => $message, 'redirect' => $redirect));
            exit;
        }

        switch ($status) {
            case 'ok':
                osc_add_flash_ok_message($message, 'admin');
                break;
            case 'warning':
                osc_add_flash_warning_message($message, 'admin');
                break;
            default:
                osc_add_flash_error_message($message, 'admin');
                break;
        }
        $this->redirectTo($redirect);
        exit;
    }

    /**
     * @return bool
     */
    private function isXhr(): bool
    {
        return strtolower(Params::getServerParam('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
    }

    /**
     * The canonical list URL: country=…&region=…&pageNum=…, empty values left out.
     *
     * @param array<string,string|int> $params
     *
     * @return string
     */
    private function listUrl(array $params = array()): string
    {
        if (isset($params['pageNum']) && (int) $params['pageNum'] <= 1) {
            unset($params['pageNum']);
        }
        $params = array_filter($params, static fn ($v): bool => $v !== '' && $v !== 0 && $v !== null);

        return osc_admin_base_url(true) . '?page=settings&action=locations'
            . ($params === array() ? '' : '&' . http_build_query($params));
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsLocations.php
