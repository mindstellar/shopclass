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
 * The profile and sign-up forms as a request carries them, read for AccountService. Controllers
 * call it; the API lays its body over the stored values in the same field names first.
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
     * @param bool $admin read the admin form's fields too: e-mail, username, password, state
     *
     * @return array<string,mixed>
     */
    public static function read(bool $admin): array
    {
        $form = [];
        foreach (self::FIELDS as $field) {
            $form[$field] = Params::getParamString($field);
        }
        $form['regionId']  = Params::getParamInt('regionId');
        $form['cityId']    = Params::getParamInt('cityId');
        $form['b_company'] = (bool) Params::getParam('b_company');
        foreach (['d_coord_lat', 'd_coord_long'] as $coord) {
            if (Params::existParam($coord)) {
                $form[$coord] = Params::getParamString($coord);
            }
        }
        if (is_array(Params::getParam('s_info'))) {
            $form['s_info'] = Params::getParamArray('s_info');
        }
        if ($admin) {
            foreach (self::ADMIN_FIELDS as $field) {
                if (Params::existParam($field)) {
                    $form[$field] = Params::getParamString($field);
                }
            }
            $form['s_password']  = Params::getParamString('s_password', false, false);
            $form['s_password2'] = Params::getParamString('s_password2', false, false);
        }

        return $form;
    }

    /**
     * The sign-up form, for AccountService::register(): the profile plus the e-mail, username
     * and password.
     *
     * @return array<string,mixed>
     */
    public static function signUp(): array
    {
        return [
            's_email'     => Params::getParamString('s_email'),
            's_username'  => Params::getParamString('s_username'),
            's_password'  => Params::getParamString('s_password', false, false),
            's_password2' => Params::getParamString('s_password2', false, false),
        ] + self::read(false);
    }
}
