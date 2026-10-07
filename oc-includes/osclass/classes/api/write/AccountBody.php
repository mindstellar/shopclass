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

namespace mindstellar\api\write;

use mindstellar\api\ProblemException;
use mindstellar\location\LocationQuery;
use mindstellar\user\AccountInput;

/**
 * An account body read as the profile form, for AccountService::update(): the stored values
 * with the sent members laid over them, so a member not sent keeps its value. The admin's
 * edit form adds the e-mail, username and password, and keeps the account's state.
 */
final class AccountBody
{
    /** API member => the profile form's field. */
    private const FIELDS = [
        'name'         => 's_name',
        'website'      => 's_website',
        'phone_land'   => 's_phone_land',
        'phone_mobile' => 's_phone_mobile',
        'city_area'    => 'cityArea',
        'address'      => 'address',
        'zip'          => 'zip',
    ];

    private function __construct()
    {
    }

    /**
     * The user's own profile form, as AccountInput::read() reads it.
     *
     * @param array<string,mixed> $user the t_user row
     * @param array<mixed>        $patch
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for a country, region or city that does not exist
     */
    public static function profile(array $user, array $patch): array
    {
        return AccountInput::fromArray(self::params($user, $patch), false);
    }

    /**
     * The admin's edit form, as AccountInput::read() reads it.
     *
     * @param array<string,mixed> $user the t_user row
     * @param array<mixed>        $patch
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for a country, region or city that does not exist
     */
    public static function admin(array $user, array $patch): array
    {
        return AccountInput::fromArray(self::adminParams($user, $patch), true);
    }

    /**
     * The profile form's request values.
     *
     * @param array<string,mixed> $user the t_user row
     * @param array<mixed>        $patch
     *
     * @return array<string,string>
     * @throws ProblemException 422 for a country, region or city that does not exist
     */
    private static function params(array $user, array $patch): array
    {
        $params = [
            's_name'         => (string) $user['s_name'],
            's_website'      => (string) $user['s_website'],
            's_phone_land'   => (string) $user['s_phone_land'],
            's_phone_mobile' => (string) $user['s_phone_mobile'],
            'countryId'      => (string) ($user['fk_c_country_code'] ?? ''),
            'country'        => (string) ($user['s_country'] ?? ''),
            'regionId'       => (string) ($user['fk_i_region_id'] ?? ''),
            'region'         => (string) ($user['s_region'] ?? ''),
            'cityId'         => (string) ($user['fk_i_city_id'] ?? ''),
            'city'           => (string) ($user['s_city'] ?? ''),
            'cityArea'       => (string) ($user['s_city_area'] ?? ''),
            'address'        => (string) ($user['s_address'] ?? ''),
            'zip'            => (string) ($user['s_zip'] ?? ''),
            'b_company'      => (string) ($user['b_company'] ?? '0'),
        ];
        foreach (self::FIELDS as $member => $field) {
            if (array_key_exists($member, $patch)) {
                $params[$field] = (string) ($patch[$member] ?? '');
            }
        }
        if (array_key_exists('is_company', $patch)) {
            $params['b_company'] = $patch['is_company'] === true ? '1' : '0';
        }
        $places = new LocationQuery();
        if (array_key_exists('country', $patch)) {
            $code = strtoupper((string) ($patch['country'] ?? ''));
            if ($code !== '' && !$places->exists(LocationQuery::COUNTRY, $code)) {
                throw ProblemException::field('/country', 'unknown', 'is not a country of this site');
            }
            $params['countryId'] = $code;
            $params['country']   = '';
        }
        foreach (['region_id' => ['regionId', 'region', LocationQuery::REGION], 'city_id' => ['cityId', 'city', LocationQuery::CITY]] as $member => [$idField, $nameField, $level]) {
            if (!array_key_exists($member, $patch)) {
                continue;
            }
            $id = (int) ($patch[$member] ?? 0);
            if ($id > 0 && !$places->exists($level, $id)) {
                throw ProblemException::field('/' . $member, 'unknown', 'does not exist');
            }
            $params[$idField]   = $id > 0 ? (string) $id : '';
            $params[$nameField] = '';
        }
        foreach (['lat' => 'd_coord_lat', 'lng' => 'd_coord_long'] as $member => $field) {
            if (array_key_exists($member, $patch)) {
                $params[$field] = $patch[$member] === null ? '' : (string) $patch[$member];
            }
        }

        return $params;
    }

    /**
     * The admin's edit form's request values: the profile, plus the e-mail, username and a new password when
     * one is sent. Active and enabled keep their stored values; the action endpoints change
     * them, so their hooks run.
     *
     * @param array<string,mixed> $user the t_user row
     * @param array<mixed>        $patch
     *
     * @return array<string,string>
     * @throws ProblemException 422 for a country, region or city that does not exist
     */
    private static function adminParams(array $user, array $patch): array
    {
        $password = array_key_exists('password', $patch) ? (string) $patch['password'] : '';

        return self::params($user, $patch) + [
            's_email'     => array_key_exists('email', $patch) ? trim((string) $patch['email']) : (string) $user['s_email'],
            's_username'  => array_key_exists('username', $patch) ? trim((string) $patch['username']) : (string) $user['s_username'],
            's_password'  => $password,
            's_password2' => $password,
            'b_enabled'   => (string) ($user['b_enabled'] ?? '0') === '1' ? '1' : '',
            'b_active'    => (string) ($user['b_active'] ?? '0') === '1' ? '1' : '',
        ];
    }
}
