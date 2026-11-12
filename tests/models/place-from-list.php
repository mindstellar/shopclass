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
 * "Only places from the list": with it on, a typed place that is not one of the site's is
 * refused on the web, admin, import and API saves; a picked or matched place is saved, and a
 * found city fills its region and country.
 *
 * Usage: php tests/models/place-from-list.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

// The API kernel needs page helpers that earlier files in the suite stub, so under the runner
// this file runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $pfOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $pfOut, $pfCode);
    $pfOut = implode("\n", $pfOut);
    echo $pfOut, "\n";
    $pfFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $pfOut, $pfM) === 1;
    $pfFail  = $pfFound ? (int) $pfM[2] : 0;
    if (!$pfFound || ($pfCode !== 0 && $pfFail === 0)) {
        $pfFail = max(1, $pfFail);
    }
    $GLOBALS['okCount']   += $pfFound ? (int) $pfM[1] : 0;
    $GLOBALS['failCount'] += $pfFail;
    if ($pfFail > 0) {
        $GLOBALS['failLabels'][] = 'place-from-list: ' . $pfFail . ' failed (exit ' . $pfCode . ')';
    }

    return;
}

$pfRoot = sys_get_temp_dir() . '/osc-place-from-list-' . getmypid() . '/';
@mkdir($pfRoot . 'uploads/', 0777, true);
@mkdir($pfRoot . 'stage/', 0777, true);
define('UPLOADS_PATH', $pfRoot . 'uploads/');
register_shutdown_function(static function () use ($pfRoot): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pfRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($pfRoot);
});

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_place_from_list');
scratchdb_pin_site_mode($admin, 'classifieds');

foreach (array(
    'OSC_CACHE_TTL'   => 60,
    'WEB_PATH'        => 'http://localhost/',
    'REL_WEB_URL'     => '/',
    'PLUGINS_PATH'    => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'        => false,
    'OSC_DEBUG'       => false,
    'OSC_CSRF_SECRET' => 'place-from-list-secret',
    'BCRYPT_COST'     => 4,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return $GLOBALS['pfRoot'];
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}
if (!function_exists('osc_item_url')) {
    function osc_item_url($locale = '')
    {
        return WEB_PATH . 'item';
    }
}
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = array())
    {
        return WEB_PATH . 'route/' . $name;
    }
}
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\Links;
use mindstellar\api\write\ImageFetcher;
use mindstellar\api\write\PhotoStage;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\Scopes;
use mindstellar\auth\Actor;
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\model\ApiCredential;
use mindstellar\security\AddressGuard;
use mindstellar\utility\SystemClock;

/** Links without the theme helpers. */
final class PlaceLinks implements Links
{
    public function listing(array $item): string
    {
        return 'http://localhost/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'http://localhost/photo/' . $resource['pk_i_id'];
    }

    public function user(int $id, string $username): string
    {
        return 'http://localhost/user/' . $id;
    }

    public function avatar(int $userId): string
    {
        return 'http://localhost/avatar/' . $userId;
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'http://localhost/api/' . ($version ?? 'v1') . '/' . $path;
    }

    public function price(?int $micros, string $symbol, ?string $code = null): string
    {
        return '';
    }
}

$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'IN', 'India');
$maha   = seed_region($admin, 'IN', 'Maharashtra');
$mumbai = seed_city($admin, $maha, 'Mumbai', 'IN');
$pune   = seed_city($admin, $maha, 'Pune', 'IN');
$cars   = seed_category($admin, 'Cars', null, $locale);
$sue    = seed_user($admin, 'sue', 'sue@example.test');
$hash   = password_hash('open sesame', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST));
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string($hash) . "'");
foreach (array(
    'enabled_users'                => '1',
    'moderate_items'               => '-1',
    'logged_user_item_validation'  => '0',
    'items_wait_time'              => '0',
    'reg_user_post'                => '0',
    'selectable_parent_categories' => '1',
    'numImages@items'              => '3',
    'maxSizeKb'                    => '2048',
    'allowedExt'                   => 'png,gif,jpg,jpeg,webp',
    'language'                     => 'en_US',
    'title_character_length'       => '100',
    'description_character_length' => '5000',
    'item_required_location'       => 'none',
) as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
(new ReflectionProperty(Category::class, 'instance'))->setValue(null, null);
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';
Params::init();

$setPref = static function (array $values): void {
    foreach ($values as $k => $v) {
        Preference::getInstance()->replace($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};

$service = new ListingService();
$form    = static fn (array $place): array => $place + array(
    'catId'        => (string) $cars,
    'countryId'    => '', 'country' => '', 'regionId' => '', 'region' => '', 'cityId' => '', 'city' => '',
    'title'        => array('en_US' => 'Red hatchback'),
    'description'  => array('en_US' => 'A small red car, one owner, full service history.'),
    'price'        => '1500',
    'contactName'  => 'Sue',
    'contactEmail' => 'sue@example.test',
);
// The place errors of a save as pointer => [code, message], or the saved listing's id.
$outcome = static function (callable $save): mixed {
    try {
        return $save()->id();
    } catch (InvalidException $e) {
        $hits = array();
        foreach ($e->errors() as $err) {
            if (in_array($err['pointer'], array('/country', '/region', '/city'), true)) {
                $hits[$err['pointer']] = array($err['code'], $err['message']);
            }
        }

        return $hits === array() ? 'other refusal: ' . $e->getMessage() : $hits;
    }
};
$user     = Actor::user($sue, '192.0.2.70');
$adminAct = Actor::admin(1, '192.0.2.70');
$add      = static fn (array $place, ?Actor $actor = null): mixed => $outcome(static fn () => $service->create(ListingInput::fromArray($form($place), $actor ?? $user, true), $actor ?? $user));
$location = static fn (int $id): ?array => $admin->query("SELECT fk_c_country_code, s_country, fk_i_region_id, s_region, fk_i_city_id, s_city FROM {$p}t_item_location WHERE fk_i_item_id = $id")->fetch_assoc();
$notListed = array('/city' => array('not_listed', 'Pick your location from the list.'));
$typedCity = array('countryId' => 'IN', 'regionId' => (string) $maha, 'city' => 'Smalltown');

harness_section('the setting');
$admin->query("DELETE FROM {$p}t_preference WHERE s_name = 'item_place_from_list'");
scratchdb_forget_cache();
osc_reset_preferences();
pin('a site with no row reads off', false, osc_item_place_from_list());

harness_section('off');
$typedOff = $add(array('city' => 'Bombay (Andheri)'));
pin('a typed city is saved as typed', array(null, null, null, 'Bombay (Andheri)'), is_int($typedOff) ? array_values(array_intersect_key($location($typedOff), array_flip(array('fk_c_country_code', 'fk_i_region_id', 'fk_i_city_id', 's_city')))) : $typedOff);

harness_section('on, web save as the user');
$setPref(array('item_place_from_list' => '1'));
pin('the setting reads on', true, osc_item_place_from_list());
pin('a typed city with no ids is refused on /city', $notListed, $add(array('city' => 'Bombay (Andheri)')));
pin('so is a typed city under a picked region', $notListed, $add($typedCity));
$picked = $add(array('countryId' => 'IN', 'regionId' => (string) $maha, 'cityId' => (string) $mumbai, 'city' => 'Mumbai, Maharashtra'));
pin('a picked city is saved with the names from its ids', array('IN', 'India', (string) $maha, 'Maharashtra', (string) $mumbai, 'Mumbai'), is_int($picked) ? array_values($location($picked)) : $picked);
$matched = $add(array('countryId' => 'IN', 'regionId' => (string) $maha, 'city' => 'Pune'));
pin('a typed name that is a city of the region counts as picked', (string) $pune, is_int($matched) ? $location($matched)['fk_i_city_id'] : $matched);
$cityOnly = $add(array('cityId' => (string) $pune));
pin('a city id alone fills its region and country', array('IN', 'India', (string) $maha, 'Maharashtra', (string) $pune, 'Pune'), is_int($cityOnly) ? array_values($location($cityOnly)) : $cityOnly);
$admin->query("UPDATE {$p}t_city SET b_active = 0 WHERE pk_i_id = " . (int) $pune);
pin('a picked city that was switched off is refused on /city', $notListed, $add(array('countryId' => 'IN', 'regionId' => (string) $maha, 'cityId' => (string) $pune)));
$admin->query("UPDATE {$p}t_city SET b_active = 1 WHERE pk_i_id = " . (int) $pune);
$admin->query("UPDATE {$p}t_region SET b_active = 0 WHERE pk_i_id = " . (int) $maha);
pin('a city in a region that was switched off is refused too', $notListed, $add(array('cityId' => (string) $mumbai)));
$admin->query("UPDATE {$p}t_region SET b_active = 1 WHERE pk_i_id = " . (int) $maha);
pin('nothing given with nothing required is saved', true, is_int($add(array())));
$setPref(array('item_required_location' => 'city'));
pin('nothing given with a city required asks for each place, not for a pick', array(
    '/country' => array('required', 'Choose a country.'),
    '/region'  => array('required', 'Choose a region.'),
    '/city'    => array('required', 'Choose a city.'),
), $add(array()));
pin('a picked region with no city asks for a city only', array('/city' => array('required', 'Choose a city.')), $add(array('countryId' => 'IN', 'regionId' => (string) $maha)));
$setPref(array('item_required_location' => 'none'));

harness_section('on, admin and import');
pin('an admin is refused the same way', $notListed, $add($typedCity, $adminAct));
$import       = new ItemActions(true);
$import->prepareDataFrom($form($typedCity), true);
pin('an admin-rights save is refused with the message', 'Pick your location from the list.', trim((string) $import->add()));
$asImport = (new ItemActions(true))->asImport();
$asImport->prepareDataFrom($form($typedCity), true);
pin('an import row is refused with the typed name', 'Smalltown is not one of the site’s places.', trim((string) $asImport->add()));
$named = (new ItemActions(true))->asImport();
$named->prepareDataFrom($form(array('countryId' => 'IN', 'region' => 'Maharashtra', 'city' => 'Mumbai')), true);
pin('an import row whose names match the list is saved', true, is_int($named->add()));
$editTyped = static fn (int $id): mixed => $outcome(static fn () => $service->update(ListingInput::fromArray(array('id' => (string) $id) + $form(array('city' => 'Bombay (Andheri)')), $adminAct, false), $adminAct, false, false));
pin('an edit that keeps an old typed place needs a pick', $notListed, is_int($typedOff) ? $editTyped($typedOff) : $typedOff);

harness_section('on, the shop address');
scratchdb_pin_site_mode($admin, 'shop');
$setPref(array('shop_country' => '', 'shop_region' => '', 'shop_city' => 'Old Harbour'));
$store = $add(array(), $adminAct);
pin('an empty place takes the typed store address, unchecked', 'Old Harbour', is_int($store) ? $location($store)['s_city'] : $store);
scratchdb_pin_site_mode($admin, 'classifieds');
scratchdb_forget_cache();
osc_reset_preferences();

harness_section('on, the API');
$validator = new Validator(Schema::components());
$facts     = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')));
$settings  = new ApiSettings(true, userKeys: true);
$call      = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($validator, $facts, $settings, $pfRoot): Response {
    $services = new ApiServices(
        $settings,
        new Scopes(),
        new ApiCredential(),
        new UserRows(),
        new SystemClock(),
        api_test_limiter(static fn () => 1),
        $facts,
        new PlaceLinks(),
        new PhotoStage($pfRoot . 'stage/', new SystemClock()),
        new ImageFetcher(new AddressGuard(static fn (string $host): array => array('10.0.0.5')), static fn (array $jobs, int $max): array => array()),
        2048 * 1024
    );
    $headers = $token === null ? array() : array('Authorization' => 'Bearer ' . $token);
    $content = '';
    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
        $content                 = (string) json_encode($body);
    }
    Params::init();
    WebIdentity::forget();

    return api_services_kernel($services, $validator)->handle(new Request($method, 'v1/' . $path, array(), $headers, '192.0.2.70', $content));
};
$token   = (string) ($call('POST', 'auth/token', array('grant_type' => 'password', 'username' => 'sue', 'password' => 'open sesame'))->body()['access_token'] ?? '');
$listing = static fn (array $place): array => $place + array(
    'category_id' => $cars,
    'title'       => 'Blue van',
    'description' => 'A blue van, one owner, full service history.',
    'price'       => '900',
    'currency'    => 'USD',
);
check('the seller signed in', $token !== '');
$r = $call('POST', 'listings', $listing(array('city' => 'Foo')), $token);
pin('a typed city is a 422 problem on /city', array(422, array('/city', 'not_listed', 'Pick your location from the list.')), array(
    $r->status(), array($r->body()['errors'][0]['pointer'] ?? null, $r->body()['errors'][0]['code'] ?? null, $r->body()['errors'][0]['message'] ?? null),
));
$r    = $call('POST', 'listings', $listing(array('city_id' => $pune)), $token);
$made = (int) ($r->body()['data']['id'] ?? 0);
pin('a city id alone is saved with its region and country ids', array(201, 'IN', (string) $maha, (string) $pune), array(
    $r->status(), $made > 0 ? $location($made)['fk_c_country_code'] : null, $made > 0 ? $location($made)['fk_i_region_id'] : null, $made > 0 ? $location($made)['fk_i_city_id'] : null,
));
$admin->query("UPDATE {$p}t_city SET b_active = 0 WHERE pk_i_id = " . (int) $pune);
$r = $call('POST', 'listings', $listing(array('city_id' => $pune)), $token);
$admin->query("UPDATE {$p}t_city SET b_active = 1 WHERE pk_i_id = " . (int) $pune);
pin('a switched-off city id is a 422 problem on /city', array(422, '/city', 'not_listed'), array($r->status(), $r->body()['errors'][0]['pointer'] ?? null, $r->body()['errors'][0]['code'] ?? null));
$setPref(array('item_place_from_list' => '0'));
$r     = $call('POST', 'listings', $listing(array('city' => 'Old Town')), $token);
$typed = (int) ($r->body()['data']['id'] ?? 0);
$setPref(array('item_place_from_list' => '1'));
pin('a listing with a typed place, posted while off', 201, $r->status());
$r = $call('PATCH', 'listings/' . $typed, array('price' => '850'), $token);
pin('an edit of only its price is not checked', 200, $r->status());
$r = $call('PATCH', 'listings/' . $typed, array('city' => 'Old Town'), $token);
pin('an edit that sends its place is', array(422, '/city'), array($r->status(), $r->body()['errors'][0]['pointer'] ?? null));

exit(harness_result());
