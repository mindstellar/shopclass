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

/**
 * The one Location field of a listing form: its value (a refused form, the listing being edited,
 * the shopper's saved place) and its markup, drawn by `gui/parts/place-field.php`. The script
 * that drives it is `place-field.js`.
 */
final class PlaceField
{
    /** The posted place keys a refused form keeps. */
    public const KEYS = array('cityId', 'city', 'regionId', 'region', 'countryId', 'country');

    /**
     * What the field holds, and `saved`: the saved place when it is offered as a button.
     *
     * @param array<string,string>     $kept    posted place keys of a refused save, '' when absent
     * @param array<string,mixed>|null $item    the listing row being edited
     * @param array<string,mixed>|null $account the saved place from account()
     *
     * @return array<string,mixed>
     */
    public static function value(array $kept, ?array $item, ?array $account, bool $offerSaved = true): array
    {
        $value          = self::compose($kept, $item, $account);
        $value['saved'] = $offerSaved && $account !== null && $account['city_id'] !== $value['city_id'] ? $account : null;

        return $value;
    }

    /**
     * Choose the value from the sources in order: kept form, listing, account.
     *
     * @param array<string,string>     $kept    posted place keys of a refused save, '' when absent
     * @param array<string,mixed>|null $item    the listing row being edited
     * @param array<string,mixed>|null $account the saved place from account()
     *
     * @return array<string,mixed>
     */
    public static function compose(array $kept, ?array $item, ?array $account): array
    {
        if (array_filter($kept, static fn (string $v): bool => $v !== '') !== array()) {
            $cityId   = (int) $kept['cityId'];
            $regionId = (int) $kept['regionId'];
            if ($cityId > 0) {
                return self::place(
                    self::label($cityId, $regionId, $kept['city'], $kept['region']),
                    $cityId,
                    $regionId,
                    $kept['countryId'],
                    'list',
                    self::name('city', $cityId, $kept['city']),
                    self::name('region', $regionId, $kept['region'])
                );
            }

            return self::typed($kept['city'], $kept['region'], $kept['country'], $regionId, $kept['countryId']);
        }
        if ($item !== null) {
            $cityId   = (int) ($item['fk_i_city_id'] ?? 0);
            $regionId = (int) ($item['fk_i_region_id'] ?? 0);
            if ($cityId > 0) {
                return self::place(
                    self::label($cityId, $regionId, (string) ($item['s_city'] ?? ''), (string) ($item['s_region'] ?? '')),
                    $cityId,
                    $regionId,
                    (string) ($item['fk_c_country_code'] ?? ''),
                    'listing',
                    self::name('city', $cityId, (string) ($item['s_city'] ?? '')),
                    self::name('region', $regionId, (string) ($item['s_region'] ?? ''))
                );
            }

            return self::typed(
                trim((string) ($item['s_city'] ?? '')),
                trim((string) ($item['s_region'] ?? '')),
                trim((string) ($item['s_country'] ?? '')),
                $regionId,
                (string) ($item['fk_c_country_code'] ?? '')
            );
        }
        if ($account !== null) {
            return self::place(
                $account['label'],
                $account['city_id'],
                $account['region_id'],
                $account['country_code'],
                $account['origin'],
                (string) ($account['city_name'] ?? ''),
                (string) ($account['region_name'] ?? '')
            );
        }

        return self::typed('', '', '');
    }

    /**
     * A user's place with a city id, or null. The account's place columns are its default
     * address, which the address book lists when it has a street.
     *
     * @param array<string,mixed>|null $user the user row
     *
     * @return array{label:string,city_id:int,region_id:int,country_code:string,origin:string,city_name:string,region_name:string}|null
     */
    public static function account(?array $user): ?array
    {
        $cityId = (int) ($user['fk_i_city_id'] ?? 0);
        if ($user === null || $cityId <= 0) {
            return null;
        }
        $regionId = (int) ($user['fk_i_region_id'] ?? 0);

        return array(
            'label'        => self::label($cityId, $regionId, (string) ($user['s_city'] ?? ''), (string) ($user['s_region'] ?? '')),
            'city_id'      => $cityId,
            'region_id'    => $regionId,
            'country_code' => (string) ($user['fk_c_country_code'] ?? ''),
            'origin'       => trim((string) ($user['s_address'] ?? '')) !== '' ? 'address' : 'account',
            'city_name'    => self::name('city', $cityId, (string) ($user['s_city'] ?? '')),
            'region_name'  => self::name('region', $regionId, (string) ($user['s_region'] ?? '')),
        );
    }

    /**
     * The options and value osc_place_field() draws, with the site's settings and the form's
     * errors filled in.
     *
     * @param array<string,mixed> $opts osc_place_field() options
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>} the options for render(), and the value
     */
    public static function prepare(array $opts): array
    {
        $admin = defined('OC_ADMIN') && OC_ADMIN;
        $given = isset($opts['error']);
        $opts += array(
            'required' => osc_item_required_location() !== 'none',
            'style'    => $admin ? 'single' : osc_item_place_style(),
            'error'    => osc_form_error('/city') ?: osc_form_error('/region') ?: osc_form_error('/country'),
        );
        $opts['style'] = self::style((string) $opts['style']);
        if ($admin) {
            $opts += array('class' => 'osc-field', 'hint_class' => 'form-text', 'error_class' => 'field-error');
        }
        $countries      = self::rows(osc_get_countries(), 'pk_c_code', 'code');
        $value          = osc_place_field_value($opts);
        $opts['strict'] = osc_item_place_from_list();
        $opts['multi']  = count($countries) > 1;
        if ($opts['style'] === 'single') {
            return array($opts, $value);
        }
        if (!$opts['multi'] && $value['country_code'] === '' && $countries !== array()) {
            $value['country_code'] = $countries[0]['code'];
        }
        $opts['lists'] = array('countries' => $countries) + self::children($value, $opts['style']);
        $opts += array(
            'errors'          => $given
                ? array('city' => (string) $opts['error'])
                : array('country' => osc_form_error('/country'), 'region' => osc_form_error('/region'), 'city' => osc_form_error('/city')),
            'required_levels' => array(
                'country' => osc_item_location_required('country'),
                'region'  => osc_item_location_required('region'),
                'city'    => osc_item_location_required('city'),
            ),
        );
        if ($opts['style'] === 'dropdown') {
            $opts += array('ajax' => osc_base_url(true));
        }

        return array($opts, $value);
    }

    /**
     * The field's markup.
     *
     * @param array<string,mixed> $opts  id, label, required, required_levels, style, strict (only places from the list), multi (several countries),
     *                                   error, errors, lists (from prepare()), ajax (the dropdown's ajax address), part_class, class, hint_class, error_class, req_class
     * @param array<string,mixed> $value from value()
     */
    public static function render(array $opts, array $value): string
    {
        $placeStyle  = self::style((string) ($opts['style'] ?? ''));
        $placeLists  = (array) ($opts['lists'] ?? array());
        $placeOpts   = $opts;
        $placeValue  = $value;
        $placeStrict = !empty($opts['strict']);
        $placeMulti  = !empty($opts['multi']);
        $placeError  = (string) ($opts['error'] ?? '');
        $placeTexts  = self::texts() + ($placeStyle === 'single' ? array() : self::levelTexts());
        ob_start();
        require ABS_PATH . 'oc-includes/osclass/gui/parts/place-field.php';

        return (string) ob_get_clean();
    }

    /**
     * The style to draw: one of single, fields, dropdown.
     */
    public static function style(string $style): string
    {
        return in_array($style, array('fields', 'dropdown'), true) ? $style : 'single';
    }

    /**
     * For the dropdown, the regions of the current country and the cities of the current region,
     * named in the visitor's language.
     *
     * @param array<string,mixed> $value from value()
     *
     * @return array{regions:list<array{id:string,name:string}>,cities:list<array{id:string,name:string}>}
     */
    private static function children(array $value, string $style): array
    {
        $out = array('regions' => array(), 'cities' => array());
        if ($style !== 'dropdown') {
            return $out;
        }
        $query = new LocationQuery();
        if ((string) $value['country_code'] !== '') {
            $out['regions'] = self::rows(_osc_location_list($query->children(LocationQuery::REGION, (string) $value['country_code']), 'region'), 'pk_i_id', 'id');
        }
        if ((int) $value['region_id'] > 0) {
            $out['cities'] = self::rows(_osc_location_list($query->children(LocationQuery::CITY, (string) $value['region_id']), 'city'), 'pk_i_id', 'id');
        }

        return $out;
    }

    /**
     * @return list<array<string,string>>
     */
    private static function rows(mixed $list, string $from, string $to): array
    {
        return array_map(static fn (array $r): array => array($to => (string) $r[$from], 'name' => (string) $r['s_name']), array_values((array) $list));
    }

    /**
     * The extra strings of the fields and dropdown styles.
     *
     * @return array<string,string>
     */
    public static function levelTexts(): array
    {
        return array(
            'select-country' => __('Select a country...'),
            'select-region'  => __('Select a region...'),
            'select-city'    => __('Select a city...'),
            'loading'        => _m('Loading…'),
        );
    }

    /**
     * The field's strings, by data-text-* name. The script fills in %s.
     *
     * @return array<string,string>
     */
    public static function texts(): array
    {
        return array(
            'searching'    => _m('Searching…'),
            'none-on'      => _m('No places match “%s”. Check the spelling, or try a bigger town nearby.'),
            'none-off'     => _m('No places match “%s”. You can keep what you typed.'),
            'more'         => _m('%s more. Keep typing to narrow it down.'),
            'error'        => _m('Couldn’t load places.'),
            'error-off'    => _m('Couldn’t load places. You can still type your place.'),
            'retry'        => _m('Try again'),
            'live-found'   => _m('Places found: %s. Use the up and down arrows to choose.'),
            'live-none'    => _m('No places match.'),
            'live-chosen'  => _m('%s chosen.'),
            'pick-error'   => _m('Pick your location from the list.'),
            'note-address' => _m('From your saved address'),
            'note-account' => _m('From your account'),
            'note-listing' => _m('This listing’s place'),
            'note-list'    => _m('Picked from the list'),
            'note-typed'   => _m('Not on the list. It will be shown as you typed it.'),
            'hint-on'      => _m('Type the first letters of your town or city, then pick it from the list.'),
            'hint-off'     => _m('Type your town or city. Pick it from the list if it is there.'),
            'hint-fields-on'  => _m('Type the first letters of your region and city, then pick each from the list.'),
            'hint-fields-off' => _m('Type your region and city. Pick each from the list if it is there.'),
            'hint-dropdown'   => _m('Choose your country, region and city.'),
        );
    }

    /**
     * "City, Region" in the visitor's language.
     */
    private static function label(int $cityId, int $regionId, string $city, string $region): string
    {
        $name = osc_location_name('city', $cityId, $city);

        return LocationNames::label($name !== '' ? $name : $city, $regionId > 0 ? osc_location_name('region', $regionId, $region) : $region);
    }

    private static function name(string $type, int $id, string $fallback): string
    {
        return $id > 0 ? osc_location_name($type, $id, $fallback) : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private static function place(string $label, int $cityId, int $regionId, string $country, string $origin, string $cityName, string $regionName): array
    {
        return array(
            'text'         => $label,
            'city_id'      => $cityId,
            'region_id'    => $regionId,
            'country_code' => $country,
            'region_text'  => '',
            'country_text' => '',
            'city_name'    => $cityName,
            'region_name'  => $regionName,
            'origin'       => $origin,
            'saved'        => null,
        );
    }

    /**
     * A place with no city id. A region id and country code are kept when given, so the fields and
     * dropdown styles do not lose a region that was picked without a city.
     *
     * @return array<string,mixed>
     */
    private static function typed(string $city, string $region, string $country, int $regionId = 0, string $countryCode = ''): array
    {
        return array(
            'text'         => $city,
            'city_id'      => 0,
            'region_id'    => $regionId,
            'country_code' => $countryCode,
            'region_text'  => $region,
            'country_text' => $country,
            'city_name'    => $city,
            'region_name'  => self::name('region', $regionId, $region),
            'origin'       => '',
            'saved'        => null,
        );
    }
}
