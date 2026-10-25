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
 * Pins the installer's shop set-up requests: they need the nonce and a finished database step,
 * only Aisle and the plugins ticked on the site-type step are installed, a theme that fails
 * leaves the site classifieds, and shop mode is set only after Aisle is the theme.
 *
 * DB-free, with a fake fetcher.  Usage: php tests/install-package-actions.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');
define('OSCLASS_VERSION', '7.1.0');

$GLOBALS['installsOff'] = false;
$GLOBALS['prefs']       = array();

function osc_market_changes_blocked()
{
    return $GLOBALS['installsOff'];
}

function osc_package_installs_disabled()
{
    return $GLOBALS['installsOff'];
}

function osc_set_preference($key, $value)
{
    $GLOBALS['prefs'][] = array($key, $value);
}

function osc_run_hook($hook, ...$args)
{
}

function __($text)
{
    return $text;
}

function _m($text)
{
    return $text;
}

require_once LIB_PATH . 'vendor/autoload.php';
require_once LIB_PATH . 'osclass/install-functions.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\install\ShopSetup;

$release = static fn (string $version, string $requires = '7.0.0'): array => array(
    'version' => $version, 'requires' => $requires, 'url' => 'https://github.com/x/' . $version . '.zip', 'sha256' => str_repeat('a', 64),
);

/**
 * A ShopSetup on a fixture catalogue. $themeOk: whether a theme fetch puts Aisle on disk.
 *
 * @return array{0: ShopSetup, 1: ArrayObject}
 */
$make = static function (bool $themeOk = true) use ($release): array {
    $log  = new ArrayObject(array('fetched' => array(), 'switched' => array(), 'aisle' => false));
    $setup = new ShopSetup(
        array('aisle' => array($release('1.0.0'), $release('9.0.0', '99.0.0'))),
        array('aisle' => array('slug' => 'aisle')),
        array('shipping' => array($release('0.1.1')), 'razorpay' => array($release('0.1.0'))),
        array(),
        static function (string $type, string $slug, array $entry) use ($log, $themeOk): array {
            $log['fetched'] = array_merge($log['fetched'], array($type . ':' . $slug . '@' . $entry['version']));
            if ($type === 'theme' && $themeOk) {
                $log['aisle'] = true;
            }

            return array('ok' => $type !== 'theme' || $themeOk, 'message' => 'fake');
        },
        static fn (): ?array => $log['aisle'] ? array('required' => array(), 'recommended' => array('shipping' => '^0.1.0', 'razorpay' => '^0.1.0')) : null,
        static function (string $slug) use ($log): ?string {
            $log['switched'] = array_merge($log['switched'], array($slug));

            return null;
        },
        array()
    );

    return array($setup, $log);
};

// The session starts before any output, so its cookie can still be set.
$session = Session::getInstance();
$session->_set('install_nonce', 'n1');
$choose  = static function (string $type, array $plugins) use ($session): void {
    $_SESSION = array();
    $session->_set('install_site_type', $type);
    $session->_set('install_shop_plugins', $plugins);
};
$prefSet = static fn (string $key): array => array_values(array_filter($GLOBALS['prefs'], static fn ($p) => $p[0] === $key));

harness_section('the request guard');
Params::setParam('install_nonce', '');
check('no nonce is refused', install_ajax_allowed() === false);
Params::setParam('install_nonce', 'n1');
check('a nonce without the database step is refused', install_ajax_allowed() === false);
$session->_set('install_db_done', 1);
Params::setParam('install_nonce', 'wrong');
check('a wrong nonce is refused', install_ajax_allowed() === false);
Params::setParam('install_nonce', 'n1');
check('the nonce and the database step pass', install_ajax_allowed() === true);
$loc = (string) file_get_contents(LIB_PATH . 'osclass/install-location.php');
check('install-location.php checks the guard before any shop step', strpos($loc, 'install_ajax_allowed()') !== false
    && strpos($loc, 'install_ajax_allowed()') < strpos($loc, 'install_shop_step('));

harness_section('the allow-list');
[$setup, $log] = $make();
$log['aisle'] = true;
$choose('shop', array('shipping'));
check('a plugin that was not ticked is refused', install_shop_step('install_package', 'plugin', 'razorpay', $setup)['ok'] === false);
check('a theme other than Aisle is refused', install_shop_step('install_package', 'theme', 'storefront', $setup)['ok'] === false);
check('a path is refused', install_shop_step('install_package', 'plugin', '../shipping', $setup)['ok'] === false);
check('an unknown action is refused', install_shop_step('drop_tables', 'theme', 'aisle', $setup)['ok'] === false);
check('nothing was fetched', $log['fetched'] === array());
$choose('classifieds', array('shipping'));
check('on a classifieds site even Aisle is refused', install_shop_step('install_package', 'theme', 'aisle', $setup)['ok'] === false);
check('and so is turning shop mode on', install_shop_step('activate_theme', '', '', $setup)['ok'] === false);
check('still nothing fetched or set', $log['fetched'] === array() && $GLOBALS['prefs'] === array());

harness_section('the theme fails');
[$setup, $log] = $make(false);
$choose('shop', array('shipping'));
$GLOBALS['prefs'] = array();
check('the theme step reports the failure', install_shop_step('install_package', 'theme', 'aisle', $setup)['ok'] === false);
check('a plugin is skipped', install_shop_step('install_package', 'plugin', 'shipping', $setup)['ok'] === false);
check('only the theme was fetched', $log['fetched'] === array('theme:aisle@1.0.0'));
check('turning shop mode on fails', install_shop_step('activate_theme', '', '', $setup)['ok'] === false);
check('site_mode stays classifieds', $prefSet('site_mode') === array());
check('the theme is not changed', $prefSet('theme') === array());
$failed = $session->_get('install_shop_failed');
check('the finish page gets both failures', isset($failed['aisle'], $failed['shipping']) && $failed['shipping']['type'] === 'plugin');

harness_section('the shop installs');
[$setup, $log] = $make();
$choose('shop', array('shipping'));
$GLOBALS['prefs'] = array();
check('the theme installs', install_shop_step('install_package', 'theme', 'aisle', $setup)['ok'] === true);
check('the newest release this core can run was fetched', $log['fetched'] === array('theme:aisle@1.0.0'));
check('the ticked plugin installs', install_shop_step('install_package', 'plugin', 'shipping', $setup)['ok'] === true);
check('and is switched on', $log['switched'] === array('shipping'));
check('shop mode turns on', install_shop_step('activate_theme', '', '', $setup)['ok'] === true);
check('Aisle becomes the theme', $prefSet('theme') === array(array('theme', 'aisle')));
check('site_mode is shop', $prefSet('site_mode') === array(array('site_mode', 'shop')));
$keys = array_column($GLOBALS['prefs'], 0);
check('site_mode is set after the theme', array_search('theme', $keys, true) < array_search('site_mode', $keys, true));
check('no failures are left for the finish page', $session->_get('install_shop_failed') === array());

harness_section('package installs turned off');
[$setup, $log] = $make();
$GLOBALS['installsOff'] = true;
check('the theme is refused', $setup->installTheme()['ok'] === false);
check('without a fetch', $log['fetched'] === array());
$GLOBALS['installsOff'] = false;

exit(harness_result());
