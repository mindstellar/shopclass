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

namespace mindstellar\billing;

use mindstellar\location\LocationQuery;

/**
 * Where cash on delivery is offered. The areas are a JSON list of `{type, id}` in the
 * `billing_cod_areas` preference: a country by code, a region or city by id. An empty list means
 * everywhere.
 *
 * An address is inside an area when its country is the area's country (code or name) and, for
 * a region, its state is the region (id or name), and, for a city, its state is the city's region
 * and its city is the city (name). Names are compared ignoring case, accents and extra spaces.
 * An address that lacks a field an area needs is outside it.
 */
final class CodAreas
{
    public const PREF = 'billing_cod_areas';

    public const COUNTRY = 'country';
    public const REGION  = 'region';
    public const CITY    = 'city';

    /** Most areas one list holds. */
    public const MAX = 100;

    /**
     * @return array<int,array{type:string,id:string}>
     */
    public static function stored(): array
    {
        return self::clean(osc_get_preference(self::PREF));
    }

    /**
     * Keeps well-formed, distinct entries from a JSON string or a decoded list, up to MAX.
     *
     * @param mixed $raw
     *
     * @return array<int,array{type:string,id:string}>
     */
    public static function clean($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $out = array();
        foreach (is_array($raw) ? $raw : array() as $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            $id   = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (!is_string($type) || !(is_string($id) || is_int($id))) {
                continue;
            }
            $id = trim((string) $id);
            if ($type === self::COUNTRY) {
                $id = strtoupper($id);
                $ok = preg_match('/^[A-Z]{2}$/', $id) === 1;
            } else {
                $ok = ($type === self::REGION || $type === self::CITY) && preg_match('/^[1-9][0-9]{0,9}$/', $id) === 1;
            }
            if ($ok) {
                $out[$type . ':' . $id] = array('type' => $type, 'id' => $id);
            }
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * The areas that still exist, with the names a screen shows. An area whose place was
     * deleted is dropped.
     *
     * @param array<int,array{type:string,id:string}> $areas
     *
     * @return array<int,array<string,mixed>> type, id, name, path, plus the place's lineage
     */
    public static function places(array $areas): array
    {
        $query = new LocationQuery();
        $names = array();
        $name  = static function (string $code) use ($query, &$names): string {
            $names[$code] ??= (string) ($query->find(LocationQuery::COUNTRY, $code)['s_name'] ?? '');

            return $names[$code];
        };
        $out = array();
        foreach ($areas as $area) {
            $row = $query->find($area['type'], $area['type'] === self::COUNTRY ? $area['id'] : (int) $area['id']);
            if ($row === null) {
                continue;
            }
            if ($area['type'] === self::COUNTRY) {
                $out[] = $area + array('name' => $row['s_name'], 'path' => '', 'country' => $area['id'], 'country_name' => $row['s_name'], 'region' => 0, 'region_name' => '');
                continue;
            }
            $region = $area['type'] === self::REGION ? $row : $query->find(LocationQuery::REGION, (int) $row['fk_i_region_id']);
            if ($region === null) {
                continue;
            }
            $code   = strtoupper($region['fk_c_country_code']);
            $out[]  = $area + array(
                'name'         => $row['s_name'],
                'path'         => $area['type'] === self::REGION ? $name($code) : trim($region['s_name'] . ', ' . $name($code), ', '),
                'country'      => $code,
                'country_name' => $name($code),
                'region'       => (int) $region['pk_i_id'],
                'region_name'  => $region['s_name'],
            );
        }

        return $out;
    }

    /**
     * Whether cash on delivery is offered for this address.
     *
     * @param array<string,mixed> $address country, state and city as typed
     */
    public static function allows(array $address): bool
    {
        $areas = self::stored();
        if ($areas === array()) {
            return true;
        }
        $country = self::text($address['country'] ?? '');
        $state   = self::text($address['state'] ?? '');
        $city    = self::text($address['city'] ?? '');
        if ($country === '') {
            return false;
        }
        foreach (self::places($areas) as $place) {
            if (!self::sameCountry($country, $place)) {
                continue;
            }
            if ($place['type'] === self::COUNTRY) {
                return true;
            }
            if ($state === '' || !self::sameRegion($state, $place)) {
                continue;
            }
            if ($place['type'] === self::REGION || ($city !== '' && self::fold($city) === self::fold($place['name']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $place
     */
    private static function sameCountry(string $country, array $place): bool
    {
        return strtoupper($country) === $place['country'] || self::fold($country) === self::fold($place['country_name']);
    }

    /**
     * @param array<string,mixed> $place
     */
    private static function sameRegion(string $state, array $place): bool
    {
        return (ctype_digit($state) && (int) $state === $place['region']) || self::fold($state) === self::fold($place['region_name']);
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function fold(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', remove_accents($name))), 'UTF-8');
    }
}
