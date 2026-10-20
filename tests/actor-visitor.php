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
 * Actor::visitor() never carries an admin, even when one is signed in; visitorOrAdmin() does.
 * Both carry the signed-in user, the request address and the secret. fromSession() picks one role.
 *
 * DB-free.  Usage: php tests/actor-visitor.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\auth\Actor;

$GLOBALS['session'] = array('user' => 0, 'admin' => 0);

if (!function_exists('osc_is_web_user_logged_in')) {
    function osc_is_web_user_logged_in(): bool
    {
        return $GLOBALS['session']['user'] > 0;
    }
}
if (!function_exists('osc_logged_user_id')) {
    function osc_logged_user_id(): int
    {
        return $GLOBALS['session']['user'];
    }
}
if (!function_exists('osc_is_admin_user_logged_in')) {
    function osc_is_admin_user_logged_in(): bool
    {
        return $GLOBALS['session']['admin'] > 0;
    }
}
if (!function_exists('osc_logged_admin_id')) {
    function osc_logged_admin_id(): int
    {
        return $GLOBALS['session']['admin'];
    }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
Params::init();

harness_section('User and admin both signed in');
$GLOBALS['session'] = array('user' => 12, 'admin' => 3);

$visitor = Actor::visitor('s3cret');
pin('visitor() carries the user', 12, $visitor->userId());
pin('visitor() has no admin', null, $visitor->adminId());
check('visitor() is not an admin', !$visitor->isAdmin());
pin('visitor() carries the address', '203.0.113.7', $visitor->ip());
pin('visitor() carries the secret', 's3cret', $visitor->secret());

$either = Actor::visitorOrAdmin('s3cret');
pin('visitorOrAdmin() carries the user', 12, $either->userId());
pin('visitorOrAdmin() carries the admin', 3, $either->adminId());
pin('visitorOrAdmin() carries the address', '203.0.113.7', $either->ip());
pin('visitorOrAdmin() carries the secret', 's3cret', $either->secret());

harness_section('Guest');
$GLOBALS['session'] = array('user' => 0, 'admin' => 0);
$guest = Actor::visitorOrAdmin('k');
pin('no user', null, $guest->userId());
pin('no admin', null, $guest->adminId());
check('is a guest', $guest->isGuest());
pin('still carries the secret', 'k', $guest->secret());

harness_section('fromSession');
$GLOBALS['session'] = array('user' => 12, 'admin' => 3);
$asAdmin = Actor::fromSession(true);
pin('admin: admin id', 3, $asAdmin->adminId());
pin('admin: no user', null, $asAdmin->userId());
pin('admin: address', '203.0.113.7', $asAdmin->ip());
$asUser = Actor::fromSession(false);
pin('user: user id', 12, $asUser->userId());
pin('user: no admin', null, $asUser->adminId());
pin('user: address', '203.0.113.7', $asUser->ip());

exit(harness_result());
