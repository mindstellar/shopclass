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

namespace mindstellar\user;

/**
 * How a delivery address is written in each country: which of line1, line2, city, state and
 * postcode show and in what order, which are needed, their labels and the postcode pattern.
 * The map is kept by hand in code; a country not in it gets the default. Name, phone, line1, city
 * and country are needed everywhere.
 */
final class AddressFormat
{
    private const STD     = array('line1', 'line2', 'city', 'state', 'postcode');
    private const EU      = array('line1', 'line2', 'postcode', 'city');
    private const EU_AREA = array('line1', 'line2', 'postcode', 'city', 'state');
    private const CITY_PC = array('line1', 'line2', 'city', 'postcode');
    private const CITY    = array('line1', 'line2', 'city');
    private const CITY_ST = array('line1', 'line2', 'city', 'state');
    private const BIG     = array('postcode', 'state', 'city', 'line1', 'line2');

    private const BASE = array('line1', 'city');

    /**
     * Per country: fields, extra required fields, state label code, postcode label code, pattern
     * (anchored; the same text works in PCRE and in JavaScript) and an example postcode.
     */
    private const MAP = array(
        'US' => array(self::STD, array('state', 'postcode'), 'state', 'zip', '^\d{5}(-\d{4})?$', '78701'),
        'CA' => array(self::STD, array('state', 'postcode'), 'province', 'postal', '^[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d$', 'K1A 0B1'),
        'MX' => array(self::STD, array('state', 'postcode'), 'state', 'postal', '^\d{5}$', '06600'),
        'BR' => array(self::STD, array('state', 'postcode'), 'state', 'cep', '^\d{5}-?\d{3}$', '01310-100'),
        'AR' => array(self::STD, array('postcode'), 'province', 'postal', '^([A-Za-z]\d{4}[A-Za-z]{3}|\d{4})$', 'C1425ABC'),
        'CL' => array(self::STD, array('postcode'), 'region', 'postal', '^\d{3}-?\d{4}$', '8320000'),
        'CO' => array(self::STD, array('postcode'), 'province', 'postal', '^\d{6}$', '110111'),
        'PE' => array(self::STD, array(), 'region', 'postal', '^\d{5}$', '15001'),
        'GB' => array(self::STD, array('postcode'), 'county', 'postcode', '^[A-Za-z]{1,2}\d[A-Za-z\d]? ?\d[A-Za-z]{2}$', 'SW1A 1AA'),
        'IE' => array(self::STD, array(), 'county', 'eircode', '^[A-Za-z]\d{2} ?[A-Za-z\d]{4}$', 'D02 X285'),
        'FR' => array(self::EU, array('postcode'), null, 'postal', '^\d{5}$', '75001'),
        'DE' => array(self::EU, array('postcode'), null, 'postal', '^\d{5}$', '10115'),
        'NL' => array(self::EU, array('postcode'), null, 'postcode', '^\d{4} ?[A-Za-z]{2}$', '1012 AB'),
        'BE' => array(self::EU, array('postcode'), null, 'postal', '^\d{4}$', '1000'),
        'LU' => array(self::EU, array('postcode'), null, 'postal', '^\d{4}$', '1111'),
        'ES' => array(self::EU_AREA, array('postcode'), 'province', 'postal', '^\d{5}$', '28013'),
        'PT' => array(self::EU, array('postcode'), null, 'postal', '^\d{4}-?\d{3}$', '1100-148'),
        'IT' => array(self::EU_AREA, array('postcode'), 'province', 'postal', '^\d{5}$', '00184'),
        'CH' => array(self::EU, array('postcode'), null, 'postal', '^\d{4}$', '8001'),
        'AT' => array(self::EU, array('postcode'), null, 'postal', '^\d{4}$', '1010'),
        'DK' => array(self::EU, array('postcode'), null, 'postal', '^\d{4}$', '1050'),
        'SE' => array(self::EU, array('postcode'), null, 'postal', '^\d{3} ?\d{2}$', '111 22'),
        'NO' => array(self::EU, array('postcode'), null, 'postal', '^\d{4}$', '0150'),
        'FI' => array(self::EU, array('postcode'), null, 'postal', '^\d{5}$', '00100'),
        'PL' => array(self::EU, array('postcode'), null, 'postal', '^\d{2}-?\d{3}$', '00-001'),
        'CZ' => array(self::EU, array('postcode'), null, 'postal', '^\d{3} ?\d{2}$', '110 00'),
        'GR' => array(self::EU, array('postcode'), null, 'postal', '^\d{3} ?\d{2}$', '105 57'),
        'TR' => array(self::EU_AREA, array('postcode'), 'province', 'postal', '^\d{5}$', '34000'),
        'IL' => array(self::CITY_PC, array('postcode'), null, 'postal', '^\d{5}(\d{2})?$', '6100000'),
        'AE' => array(self::CITY_ST, array(), 'emirate', null, null, ''),
        'SA' => array(self::STD, array('postcode'), 'region', 'postal', '^\d{5}(-\d{4})?$', '12211'),
        'QA' => array(self::CITY, array(), null, null, null, ''),
        'KW' => array(self::STD, array('postcode'), 'governorate', 'postal', '^\d{5}$', '13001'),
        'EG' => array(self::STD, array(), 'governorate', 'postal', '^\d{5}$', '11511'),
        'ZA' => array(self::STD, array('postcode'), 'province', 'postal', '^\d{4}$', '2000'),
        'NG' => array(self::STD, array(), 'state', 'postal', '^\d{6}$', '100001'),
        'KE' => array(self::STD, array(), 'county', 'postal', '^\d{5}$', '00100'),
        'IN' => array(self::STD, array('state', 'postcode'), 'state', 'pin', '^\d{6}$', '110001'),
        'PK' => array(self::STD, array(), 'province', 'postal', '^\d{5}$', '44000'),
        'BD' => array(self::STD, array(), 'region', 'postal', '^\d{4}$', '1207'),
        'LK' => array(self::STD, array(), 'province', 'postal', '^\d{5}$', '00100'),
        'JP' => array(self::BIG, array('state', 'postcode'), 'prefecture', 'postal', '^\d{3}-?\d{4}$', '100-0001'),
        'KR' => array(self::BIG, array('postcode'), 'province', 'postal', '^\d{5}$', '04524'),
        'CN' => array(self::BIG, array('state', 'postcode'), 'province', 'postal', '^\d{6}$', '100000'),
        'HK' => array(self::CITY, array(), null, null, null, ''),
        'SG' => array(self::CITY_PC, array('postcode'), null, 'postal', '^\d{6}$', '049483'),
        'MY' => array(self::EU_AREA, array('postcode'), 'state', 'postal', '^\d{5}$', '50450'),
        'TH' => array(self::STD, array('postcode'), 'province', 'postal', '^\d{5}$', '10110'),
        'ID' => array(self::STD, array('postcode'), 'province', 'postal', '^\d{5}$', '10110'),
        'PH' => array(self::STD, array('postcode'), 'province', 'postal', '^\d{4}$', '1000'),
        'VN' => array(self::STD, array(), 'province', 'postal', '^\d{5,6}$', '100000'),
        'AU' => array(self::EU_AREA, array('state', 'postcode'), 'state', 'postcode', '^\d{4}$', '2000'),
        'NZ' => array(self::CITY_PC, array('postcode'), null, 'postcode', '^\d{4}$', '6011'),
    );

    private function __construct()
    {
    }

    /**
     * One country's format; an unknown or empty code gets the default.
     *
     * @return array{fields:list<string>,required:list<string>,state:string,postcode:string,pattern:?string,example:string}
     */
    public static function for(string $country): array
    {
        $row = self::MAP[strtoupper(trim($country))] ?? null;
        if ($row === null) {
            return array(
                'fields'   => self::STD,
                'required' => self::BASE,
                'state'    => self::stateLabel('stateprovince'),
                'postcode' => self::postcodeLabel('postcode'),
                'pattern'  => null,
                'example'  => '',
            );
        }
        [$fields, $needed, $state, $postcode, $pattern, $example] = $row;

        return array(
            'fields'   => $fields,
            'required' => array_values(array_intersect($fields, array_merge(self::BASE, $needed))),
            'state'    => $state === null ? '' : self::stateLabel($state),
            'postcode' => $postcode === null ? '' : self::postcodeLabel($postcode),
            'pattern'  => $pattern,
            'example'  => $example,
        );
    }

    /**
     * The format of every country in the map, by country code.
     *
     * @return array<string,array{fields:list<string>,required:list<string>,state:string,postcode:string,pattern:?string,example:string}>
     */
    public static function all(): array
    {
        $out = array();
        foreach (array_keys(self::MAP) as $country) {
            $out[$country] = self::for($country);
        }

        return $out;
    }

    /**
     * What is missing or malformed in a cleaned address: the fields its country needs (when
     * $ships), a typed postcode against the country's pattern, and the phone. Lengths are not checked.
     *
     * @param array<string,string> $address ADDRESS keys, cleaned
     * @param string               $at      the pointer the fields sit under, e.g. `/address`
     *
     * @return list<array{pointer:string,code:string,message:string}>
     */
    public static function problems(array $address, string $at, bool $ships): array
    {
        $format   = self::for($address['country'] ?? '');
        $required = $ships ? array_merge(array('name', 'phone', 'country'), $format['required']) : array();
        $out      = array();
        foreach (array('name', 'phone', 'line1', 'city', 'state', 'postcode', 'country') as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            $error = null;
            if ($value === '') {
                $error = in_array($field, $required, true) ? array('required', _m('This field is needed.')) : null;
            } elseif ($field === 'phone') {
                $error = self::phoneProblem($value);
            } elseif ($field === 'postcode' && $format['pattern'] !== null && preg_match('/' . $format['pattern'] . '/iu', $value) !== 1) {
                $error = array('pattern', sprintf(_m('%1$s should look like %2$s.'), $format['postcode'], $format['example']));
            }
            if ($error !== null) {
                $out[] = array('pointer' => $at . '/' . $field, 'code' => $error[0], 'message' => $error[1]);
            }
        }

        return $out;
    }

    /**
     * What is wrong with a phone number as typed: one optional leading plus, then digits, spaces
     * and `- . ( ) /`, with 4 to 15 digits. Null when fine.
     *
     * @return array{0:string,1:string}|null [code, message]
     */
    public static function phoneProblem(string $phone): ?array
    {
        $digits = strlen((string) preg_replace('/\D+/', '', $phone));
        if (preg_match('/^\+?[\d\s().\/-]+$/', $phone) !== 1 || $digits < 4 || $digits > 15) {
            return array('pattern', _m('Enter a phone number, with the country code if it is abroad.'));
        }

        return null;
    }

    private static function stateLabel(string $code): string
    {
        return match ($code) {
            'state'       => _m('State'),
            'province'    => _m('Province'),
            'county'      => _m('County'),
            'prefecture'  => _m('Prefecture'),
            'region'      => _m('Region'),
            'emirate'     => _m('Emirate'),
            'governorate' => _m('Governorate'),
            default       => _m('State / Province'),
        };
    }

    private static function postcodeLabel(string $code): string
    {
        return match ($code) {
            'zip'    => _m('ZIP code'),
            'pin'    => _m('PIN code'),
            'postal' => _m('Postal code'),
            'eircode' => _m('Eircode'),
            'cep'    => _m('CEP'),
            default  => _m('Postcode'),
        };
    }
}
