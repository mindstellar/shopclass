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

use Params;

/**
 * The profile and sign-up forms, read for AccountService from the request or from plain values
 * in the same field names, as the API lays its body over the stored values.
 */
final class AccountInput
{
    /** Fields every profile form posts. */
    private const FIELDS = [
        's_name', 's_website', 's_phone_land', 's_phone_mobile', 'countryId', 'country', 'region', 'city',
        'cityArea', 'address', 'zip',
    ];

    /** Fields only the admin's user form posts. */
    private const ADMIN_FIELDS = ['s_email', 's_username', 'b_enabled', 'b_active'];

    private function __construct()
    {
    }

    /**
     * The profile form of this request.
     *
     * @param bool $admin read the admin form's fields too: e-mail, username, password, state
     *
     * @return array<string,mixed>
     */
    public static function read(bool $admin): array
    {
        return self::fromArray(self::request(), $admin);
    }

    /**
     * The sign-up form of this request, for AccountService::register(): the profile plus the
     * e-mail, username and password.
     *
     * @return array<string,mixed>
     */
    public static function signUp(): array
    {
        return self::signUpFromArray(self::request());
    }

    /**
     * read() from plain values in the form's field names, filtered as the request's are.
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    public static function fromArray(array $values, bool $admin): array
    {
        $form = [];
        foreach (self::FIELDS as $field) {
            $form[$field] = self::text($values, $field);
        }
        $form['regionId']  = self::int($values, 'regionId');
        $form['cityId']    = self::int($values, 'cityId');
        $form['b_company'] = (bool) Params::purifyText($values['b_company'] ?? '');
        foreach (['d_coord_lat', 'd_coord_long'] as $coord) {
            if (isset($values[$coord])) {
                $form[$coord] = self::text($values, $coord);
            }
        }
        if (isset($values['s_info']) && is_array($values['s_info'])) {
            $form['s_info'] = Params::purifyText($values['s_info']);
        }
        if ($admin) {
            foreach (self::ADMIN_FIELDS as $field) {
                if (isset($values[$field])) {
                    $form[$field] = self::text($values, $field);
                }
            }
            $form['s_password']  = self::raw($values, 's_password');
            $form['s_password2'] = self::raw($values, 's_password2');
        }

        return $form;
    }

    /**
     * signUp() from plain values in the form's field names.
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    public static function signUpFromArray(array $values): array
    {
        return [
            's_email'     => self::text($values, 's_email'),
            's_username'  => self::text($values, 's_username'),
            's_password'  => self::raw($values, 's_password'),
            's_password2' => self::raw($values, 's_password2'),
        ] + self::fromArray($values, false);
    }

    /**
     * @return array<string,mixed> the request's values, unfiltered
     */
    private static function request(): array
    {
        return Params::getParamsAsArray('', false);
    }

    /**
     * A scalar value with every tag taken out, as Params::getParamString() reads it; '' for an array.
     *
     * @param array<string,mixed> $values
     */
    private static function text(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return $value === null || is_array($value) ? '' : (string) Params::purifyText((string) $value);
    }

    /**
     * A scalar value as sent, for a password.
     *
     * @param array<string,mixed> $values
     */
    private static function raw(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return $value === null || is_array($value) ? '' : (string) $value;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function int(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        return $value === null || is_array($value) ? 0 : (int) $value;
    }
}
