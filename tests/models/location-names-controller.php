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
 * The Names section of the locations edit drawer, posted to the real controller: names[] reach
 * t_location_name, a post without names keeps them, the drawer is filled from them, and a post
 * that fails the CSRF check writes nothing.
 *
 * Usage:  php tests/models/location-names-controller.php
 *         php tests/run-models.php location-names-controller
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

use mindstellar\location\LocationNameStore;

// A process of its own, as earlier files in the suite define DEMO, which locks LocationService.
if (api_admin_isolated(__FILE__)) {
    return;
}

/** Thrown in place of the exit() a real redirect ends the request with. */
class NamesRedirect extends RuntimeException
{
}

// A refused CSRF check redirects and exits, so a refused post runs in a child process with its
// own database; it reports the stored names on the way out, before that database is dropped.
$child = ($argv[1] ?? '') === 'refused';
if ($child) {
    register_shutdown_function(static function () use (&$region, &$city): void {
        echo 'stored: ', json_encode(array((new LocationNameStore())->all('region', $region), (new LocationNameStore())->all('city', $city))), "\n";
    });
}
$admin = scratchdb_session('osc_models_location_names_controller' . ($child ? '_child' : ''));

foreach (array('_m', '__') as $translate) {
    if (!function_exists($translate)) {
        eval('function ' . $translate . '($text) { return $text; }');
    }
}
foreach (array('error', 'ok', 'warning') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") { $GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}
if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
    }
}
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';

/** The real controller, with a redirect that returns control to the test. */
class TestLocations extends CAdminSettingsLocations
{
    public function redirectTo($url, $code = null)
    {
        throw new NamesRedirect((string) $url);
    }
}

$p = DB_TABLE_PREFIX;
seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');
$country = seed_country($admin, 'DE', 'Germany');
$region  = seed_region($admin, $country, 'Bavaria');
$city    = seed_city($admin, $region, 'Munich', $country);
harness_reset_container();
$store = new LocationNameStore();

/** One request to the controller with a valid CSRF token unless $request names one; returns what it ended in. */
$post = static function (array $request): string {
    $csrf     = new \mindstellar\security\Csrf();
    $_GET     = array();
    $_POST    = $_REQUEST = $request + array(
        'page' => 'settings', 'action' => 'locations',
        'CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue(),
    );
    $GLOBALS['flashes'] = array();
    Params::init();
    try {
        (new ReflectionClass('TestLocations'))->newInstanceWithoutConstructor()->doModel();
    } catch (NamesRedirect $e) {
        return (string) ($GLOBALS['flashes'][0] ?? 'redirect');
    }

    return 'no answer';
};

if ($child) {
    $store->set('region', $region, 'de_DE', 'Bayern');
    $store->set('city', $city, 'de_DE', 'München');
    $id     = $argv[2] === 'edit_region' ? array('region_id' => $region, 'e_region' => 'Bavaria') : array('city_id' => $city, 'e_city' => 'Munich');
    $answer = $post(array('type' => $argv[2], 'names' => array('de_DE' => 'Gestohlen'), 'CSRFToken' => 'forged') + $id);
    echo 'controller answered: ', $answer, "\n";
    exit(0);
}
$refused = static function (string $type): string {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' refused ' . $type . ' 2>&1', $out);

    return implode("\n", $out);
};

harness_section('saving names');
$post(array('type' => 'edit_region', 'region_id' => $region, 'e_region' => 'Bavaria', 'names' => array('de_DE' => 'Bayern', 'en_US' => '')));
pin('a region edit saves its names', array('de_DE' => 'Bayern'), $store->all('region', $region));
$post(array('type' => 'edit_city', 'city_id' => $city, 'e_city' => 'Munich', 'names' => array('de_DE' => 'München')));
pin('a city edit saves its names', array('de_DE' => 'München'), $store->all('city', $city));
$post(array('type' => 'edit_country', 'country_code' => 'DE', 'e_country' => 'Germany', 'names' => array('de_DE' => 'Deutschland')));
pin('a country edit saves its names', array('de_DE' => 'Deutschland'), $store->all('country', 'DE'));
$post(array('type' => 'edit_region', 'region_id' => $region, 'e_region' => 'Bavaria State'));
pin('an edit with no names keeps them', array('de_DE' => 'Bayern'), $store->all('region', $region));
pin('...and still renames', 'Bavaria State', (string) $admin->query("SELECT s_name FROM {$p}t_region WHERE pk_i_id = $region")->fetch_row()[0]);
$answer = $post(array('type' => 'edit_city', 'city_id' => $city, 'e_city' => 'Munich', 'names' => array('xx_XX' => 'x')));
pin('a language the site lacks is refused with its reason', 'error:xx_XX is not a language of this site', $answer);
pin('...and writes nothing', array('de_DE' => 'München'), $store->all('city', $city));

harness_section('the drawer is filled from the stored names');
$_GET = $_POST = $_REQUEST = array('form' => 'edit', 'id' => (string) $region);
Params::init();
$formModel = new ReflectionMethod('CAdminSettingsLocations', 'formModel');
$form      = $formModel->invoke((new ReflectionClass('TestLocations'))->newInstanceWithoutConstructor(), array('found' => true, 'level' => 'region'), false);
pin('one field per language, each with its stored name', array('de_DE' => 'Bayern', 'en_US' => ''), array_column($form['names'] ?? array(), 'value', 'code'));

harness_section('the CSRF check');
$untouched = 'stored: ' . json_encode(array(array('de_DE' => 'Bayern'), array('de_DE' => 'München')));
$out       = $refused('edit_region');
check('a region edit with a forged token ends before the controller answers', !str_contains($out, 'controller answered'), $out);
check('...and writes no name', str_contains($out, $untouched), $out);
$out = $refused('edit_city');
check('a city edit with a forged token ends before the controller answers', !str_contains($out, 'controller answered'), $out);
check('...and writes no name', str_contains($out, $untouched), $out);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
