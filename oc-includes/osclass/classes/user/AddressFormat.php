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
 * The data is generated from Google's address metadata into AddressFormatData; OVERRIDES holds
 * Shopclass's own changes. A country not in the data gets the default.
 */
final class AddressFormat
{
    private const STD = array('line1', 'line2', 'city', 'state', 'postcode');

    private const BASE = array('line1', 'city');

    /** Our changes on top of the generated data, by country: label types the post itself uses. */
    private const OVERRIDES = array(
        'AU' => array(3 => 'postcode'),
        'BR' => array(3 => 'cep'),
        'GB' => array(3 => 'postcode'),
        'IN' => array(3 => 'pin'),
        'NL' => array(3 => 'postcode'),
        'NZ' => array(3 => 'postcode'),
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
        $code = strtoupper(trim($country));
        $row  = AddressFormatData::MAP[$code] ?? null;
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
        [$fields, $needed, $state, $postcode, $pattern, $example] = array_replace($row, self::OVERRIDES[$code] ?? array());

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
        foreach (array_keys(AddressFormatData::MAP) as $country) {
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
            'state'          => _m('State'),
            'province', 'do_si' => _m('Province'),
            'county'         => _m('County'),
            'prefecture'     => _m('Prefecture'),
            'region', 'oblast' => _m('Region'),
            'emirate'        => _m('Emirate'),
            'area'           => _m('Area'),
            'department'     => _m('Department'),
            'district'       => _m('District'),
            'island'         => _m('Island'),
            'parish'         => _m('Parish'),
            default          => _m('State / Province'),
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
