<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * AccountInput reads plain values as it reads the request: the same fields, tags out of text,
 * passwords as sent, an array where a scalar belongs read as empty.
 *
 * DB-free.  Usage:  php tests/account-input.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\user\AccountInput;

$values = [
    's_name'      => '<b>Ann</b> Lee',
    's_email'     => 'ann@example.test',
    's_password'  => '<p>&secret',
    's_password2' => '<p>&secret',
    'regionId'    => '7',
    'cityId'      => ['8'],
    'b_company'   => '1',
    's_website'   => ['x'],
    's_info'      => ['en_US' => '<i>Hi</i>'],
    'b_enabled'   => '1',
];
$fromArray = AccountInput::signUpFromArray($values);
pin('text loses its tags, a password is kept as sent', ['Ann Lee', '<p>&secret'], [$fromArray['s_name'], $fromArray['s_password']]);
pin('ids are ints, an array where a scalar belongs is empty', [7, 0, ''], [$fromArray['regionId'], $fromArray['cityId'], $fromArray['s_website']]);
pin('the sign-up form takes no state fields', false, array_key_exists('b_enabled', $fromArray));
pin('the admin form does', '1', AccountInput::fromArray($values, true)['b_enabled'] ?? null);
pin('the request is read the same way', $fromArray, Params::withRequest($values, static fn (): array => AccountInput::signUp()));

exit(harness_result());
