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
            $cityId = (int) $kept['cityId'];
            if ($cityId > 0) {
                $regionId = (int) $kept['regionId'];

                return self::place(
                    self::label($cityId, $regionId, $kept['city'], $kept['region']),
                    $cityId,
                    $regionId,
                    $kept['countryId'],
                    'list'
                );
            }

            return self::typed($kept['city'], $kept['region'], $kept['country']);
        }
        if ($item !== null) {
            $cityId = (int) ($item['fk_i_city_id'] ?? 0);
            if ($cityId > 0) {
                $regionId = (int) ($item['fk_i_region_id'] ?? 0);

                return self::place(
                    self::label($cityId, $regionId, (string) ($item['s_city'] ?? ''), (string) ($item['s_region'] ?? '')),
                    $cityId,
                    $regionId,
                    (string) ($item['fk_c_country_code'] ?? ''),
                    'listing'
                );
            }

            return self::typed(
                trim((string) ($item['s_city'] ?? '')),
                trim((string) ($item['s_region'] ?? '')),
                trim((string) ($item['s_country'] ?? ''))
            );
        }
        if ($account !== null) {
            return self::place($account['label'], $account['city_id'], $account['region_id'], $account['country_code'], $account['origin']);
        }

        return self::typed('', '', '');
    }

    /**
     * A user's place with a city id, or null. The account's place columns are its default
     * address, which the address book lists when it has a street.
     *
     * @param array<string,mixed>|null $user the user row
     *
     * @return array{label:string,city_id:int,region_id:int,country_code:string,origin:string}|null
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
        );
    }

    /**
     * The field's markup.
     *
     * @param array<string,mixed> $opts  id, label, required, class, hint_class, error_class, req_class
     * @param array<string,mixed> $value from value()
     */
    public static function render(array $opts, array $value, bool $strict, bool $multiCountry, string $error = ''): string
    {
        $placeOpts   = $opts;
        $placeValue  = $value;
        $placeStrict = $strict;
        $placeMulti  = $multiCountry;
        $placeError  = $error;
        $placeTexts  = self::texts();
        ob_start();
        require ABS_PATH . 'oc-includes/osclass/gui/parts/place-field.php';

        return (string) ob_get_clean();
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

    /**
     * @return array<string,mixed>
     */
    private static function place(string $label, int $cityId, int $regionId, string $country, string $origin): array
    {
        return array(
            'text'         => $label,
            'city_id'      => $cityId,
            'region_id'    => $regionId,
            'country_code' => $country,
            'region_text'  => '',
            'country_text' => '',
            'origin'       => $origin,
            'saved'        => null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function typed(string $city, string $region, string $country): array
    {
        return array(
            'text'         => $city,
            'city_id'      => 0,
            'region_id'    => 0,
            'country_code' => '',
            'region_text'  => $region,
            'country_text' => $country,
            'origin'       => '',
            'saved'        => null,
        );
    }
}
