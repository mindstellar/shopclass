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
 * MenuService::coreBuiltins(): the built-in menu pages come from core's URL helpers on each
 * call, so they follow the friendly-URL setting; login and register go when users are off,
 * register when sign-up is off, and post when guests may not post or the site is a shop.
 * Usage: php tests/menu-builtins.php
 */

define('WEB_PATH', 'http://example.com/');
define('OSC_DEBUG', false);
define('OC_ADMIN', false);
define('ABS_PATH', dirname(__DIR__) . '/');
define('REL_WEB_URL', '/');

$GLOBALS['__prefs'] = array(
    'rewrite_search_url' => 'search',
    'rewrite_contact'    => 'contact',
    'rewrite_item_new'   => 'item/new',
    'rewrite_user_login' => 'user/login',
    'rewrite_user_register' => 'user/register',
);
$GLOBALS['__rw']   = false;
$GLOBALS['__site'] = array('users' => true, 'signup' => true, 'regPost' => false, 'shop' => false);

function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['__prefs'][$key] ?? '';
}
function osc_rewrite_enabled()
{
    return (bool) $GLOBALS['__rw'];
}
function osc_users_enabled()
{
    return $GLOBALS['__site']['users'];
}
function osc_user_registration_enabled()
{
    return $GLOBALS['__site']['signup'];
}
function osc_reg_user_post()
{
    return $GLOBALS['__site']['regPost'];
}
function osc_is_shop_mode(): bool
{
    return $GLOBALS['__site']['shop'];
}
function osc_category_id()
{
    return null;
}
function osc_subdomain_type()
{
    return '';
}
function osc_is_ssl()
{
    return false;
}
function osc_prune_array(&$input)
{
    $input = array_filter($input, static fn ($v): bool => $v !== '' && $v !== null);
}

require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Rewrite.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\menu\MenuService;

$urls = static fn (): array => array_map(static fn (array $b): string => $b['url'], MenuService::coreBuiltins());

harness_section('permalinks');
pin('friendly URLs off: the ?page= form', array(
    'home'     => 'http://example.com/',
    'search'   => 'http://example.com/index.php?page=search',
    'contact'  => 'http://example.com/index.php?page=contact',
    'post'     => 'http://example.com/index.php?page=item&action=item_add',
    'login'    => 'http://example.com/index.php?page=login',
    'register' => 'http://example.com/index.php?page=register&action=register',
), $urls());
$GLOBALS['__rw'] = true;
pin('friendly URLs on: the same call gives the friendly form', array(
    'home'     => 'http://example.com/',
    'search'   => 'http://example.com/search',
    'contact'  => 'http://example.com/contact',
    'post'     => 'http://example.com/item/new',
    'login'    => 'http://example.com/user/login',
    'register' => 'http://example.com/user/register',
), $urls());
pin('labels are core\'s own', 'Browse', MenuService::coreBuiltins()['search']['label']);

harness_section('switched off');
$GLOBALS['__site']['signup'] = false;
pin('sign-up off: no register', array('home', 'search', 'contact', 'post', 'login'), array_keys($urls()));
$GLOBALS['__site']['users']   = false;
$GLOBALS['__site']['regPost'] = true;
pin('users off and only members post: no login, register or post', array('home', 'search', 'contact'), array_keys($urls()));
$GLOBALS['__site']['regPost'] = false;
pin('users off but guests post: post stays', array('home', 'search', 'contact', 'post'), array_keys($urls()));
$GLOBALS['__site']['shop'] = true;
pin('a shop has no post link', array('home', 'search', 'contact'), array_keys($urls()));

exit(harness_result());
