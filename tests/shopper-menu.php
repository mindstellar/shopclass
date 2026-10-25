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
 * The account menu for a shopper: seller entries gone, "Your orders" once, plugin entries kept;
 * an admin session and classifieds mode keep the list untouched. DB-free.
 * Usage: php tests/shopper-menu.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';

$GLOBALS['state'] = [];

function osc_plugins_path()
{
    return ABS_PATH . 'oc-content/plugins/';
}

function _m($text)
{
    return $text;
}

function osc_site_mode(): string
{
    return $GLOBALS['state']['mode'];
}

function osc_is_shop_mode(): bool
{
    return osc_site_mode() === 'shop';
}

function osc_is_web_user_logged_in()
{
    return $GLOBALS['state']['user'];
}

function osc_is_admin_user_logged_in()
{
    return $GLOBALS['state']['admin'];
}

function osc_billing_enabled(): bool
{
    return $GLOBALS['state']['billing'];
}

function osc_billing_orders_url(): string
{
    return 'http://x.test/orders';
}

// hUsers.php cannot load here (it defines the sign-in helpers stubbed above), so its shopper
// code is read from the file and run against the stubs.
$file = ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$code = harness_code_only((string) file_get_contents($file));
check(
    'hUsers.php registers the listener on user_menu_filter at priority 20',
    str_contains($code, "Plugins::addHook('user_menu_filter', 'osc_shopper_user_menu', 20);")
);
check('the hidden list is a const', preg_match('/const OSC_SHOPPER_HIDDEN_MENU = array\([^)]*\);/', $code, $const) === 1);
eval($const[0]);
foreach (['osc_is_shopper', 'osc_shopper_user_menu'] as $fn) {
    $source = harness_method_source($file, $fn);
    check("$fn is in hUsers.php", $source !== '');
    eval($source);
}
osc_add_filter('user_menu_filter', 'osc_shopper_user_menu', 20);

// What the nav and core's billing/API listeners give, plus Aisle's entries.
$menu = [
    ['name' => 'Dashboard', 'url' => 'd', 'class' => 'opt_dashboard'],
    ['name' => 'Your listings', 'url' => 'i', 'class' => 'opt_items'],
    ['name' => 'Alerts', 'url' => 'a', 'class' => 'opt_alerts'],
    ['name' => 'Public profile', 'url' => 'p', 'class' => 'opt_publicprofile'],
    ['name' => 'Profile', 'url' => 'pr', 'class' => 'opt_account'],
    ['name' => 'Sign-in details', 'url' => 's', 'class' => 'opt_signin'],
    ['name' => 'Log out', 'url' => 'o', 'class' => 'opt_logout'],
    ['name' => 'Wishlist', 'url' => 'w', 'class' => 'opt_wishlist'],
    ['name' => 'Orders (Aisle)', 'url' => 'ao', 'class' => 'opt_billing_orders'],
    ['name' => 'Credits', 'url' => 'c', 'class' => 'opt_billing_wallet'],
    ['name' => 'Buy credits', 'url' => 'b', 'class' => 'opt_billing_buy'],
    ['name' => 'API access', 'url' => 'k', 'class' => 'opt_api_access'],
    ['name' => 'No class', 'url' => 'n'],
];
$classes = static fn (array $list): array => array_map(static fn ($e) => $e['class'] ?? '', $list);
$run     = static function (array $state, array $list): array {
    $GLOBALS['state'] = $state + ['mode' => 'shop', 'user' => true, 'admin' => false, 'billing' => true];

    return osc_apply_filter('user_menu_filter', $list);
};
$noOrders = array_values(array_filter($menu, static fn ($e) => ($e['class'] ?? '') !== 'opt_billing_orders'));
$twice    = array_merge($menu, [['name' => 'Orders (core)', 'url' => 'co', 'class' => 'opt_billing_orders']]);

harness_section('Shop mode, signed-in user');
$out = $run([], $menu);
pin(
    'the six seller entries are gone, the rest kept in order',
    ['opt_dashboard', 'opt_account', 'opt_signin', 'opt_logout', 'opt_wishlist', 'opt_billing_orders', ''],
    $classes($out)
);
pin('Aisle\'s own Your orders is kept', 'Orders (Aisle)', $out[5]['name']);
$out = $run([], $noOrders);
pin('core adds Your orders when nobody did', 'opt_billing_orders', end($out)['class']);
pin('...with its url', 'http://x.test/orders', end($out)['url']);
$out = $run([], $menu);
pin('core adds no second Your orders', 1, count(array_filter($out, static fn ($e) => ($e['class'] ?? '') === 'opt_billing_orders')));
$out = $run(['billing' => false], $noOrders);
check('billing off adds no Your orders', !in_array('opt_billing_orders', $classes($out), true));

harness_section('Everyone else');
pin('an admin session keeps every entry', $classes($menu), $classes($run(['admin' => true], $menu)));
pin('classifieds mode keeps every entry', $classes($menu), $classes($run(['mode' => 'classifieds'], $menu)));
pin('a guest in shop mode keeps every entry', $classes($menu), $classes($run(['user' => false], $menu)));
pin('classifieds mode keeps two entries that share a class', count($twice), count($run(['mode' => 'classifieds'], $twice)));
pin('an entry that is not an array is kept', 'x', $run([], ['x'])[0]);

exit(harness_result());

/* file end: ./tests/shopper-menu.php */
