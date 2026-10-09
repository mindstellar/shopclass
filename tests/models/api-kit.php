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
 * ApiKit, as a plugin handler uses it: listings by id keep the order given and leave out the
 * missing ones and those the caller may not see.
 * Usage: php tests/models/api-kit.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

// This file loads page helpers that earlier files in the suite stub, so under the runner it
// runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $akOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $akOut, $akCode);
    $akOut = implode("\n", $akOut);
    echo $akOut, "\n";
    $akFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $akOut, $akM) === 1;
    $akFail  = $akFound ? (int)$akM[2] : 0;
    if (!$akFound || ($akCode !== 0 && $akFail === 0)) {
        $akFail = max(1, $akFail);
    }
    $GLOBALS['okCount']   += $akFound ? (int)$akM[1] : 0;
    $GLOBALS['failCount'] += $akFail;
    if ($akFail > 0) {
        $GLOBALS['failLabels'][] = 'api-kit: ' . $akFail . ' failed (exit ' . $akCode . ')';
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_api_kit');

foreach (array(
    'OSC_CACHE_TTL' => 60,
    'WEB_PATH'      => 'http://localhost/',
    'REL_WEB_URL'   => '/',
    'PLUGINS_PATH'  => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'      => false,
    'OSC_DEBUG'     => false,
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
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

if (!function_exists('osc_prime_item_upgrades')) {
    function osc_prime_item_upgrades(array $items)
    {
    }
}
if (!function_exists('osc_remove_slash')) {
    function osc_remove_slash($var)
    {
        return is_string($var) ? stripslashes($var) : $var;
    }
}
if (!function_exists('osc_prune_array')) {
    function osc_prune_array(&$input)
    {
        \mindstellar\utility\Utils::pruneArray($input);
    }
}
if (!function_exists('osc_is_ssl')) {
    function osc_is_ssl()
    {
        return false;
    }
}

use mindstellar\api\ApiCall;
use mindstellar\api\ApiKit;
use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\Scopes;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

/** Links without the theme helpers. */
final class KitTestLinks implements Links
{
    public function listing(array $item): string
    {
        return 'http://localhost/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'http://localhost/' . $resource['s_path'] . $resource['pk_i_id'] . '.' . $resource['s_extension'];
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

    public function price(?int $micros, string $symbol): string
    {
        return number_format((int) $micros / 1000000, 2) . ' ' . $symbol;
    }
}

$p       = DB_TABLE_PREFIX;
$locale  = seed_locale($admin);
seed_currency($admin);
$country = seed_country($admin, 'US', 'United States');
$cars    = seed_category($admin, 'Cars', null, $locale);
$seller  = seed_user($admin, 'seller', 'seller@example.test');
$other   = seed_user($admin, 'other', 'other@example.test');
$first   = seed_item($admin, $cars, $seller, 'First car', 1000.0, 1, 1, $locale, $country);
$second  = seed_item($admin, $cars, $other, 'Second car', 2000.0, 1, 1, $locale, $country);
$pending = seed_item($admin, $cars, $seller, 'Pending car', 5.0, 0, 1, $locale, $country);
foreach (array('language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0', 'pageTitle' => 'Test site') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
$_COOKIE['oc_userLocale'] = 'en_US';

$facts    = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')), true, true, 10, 12, 50, false, false);
$services = new ApiServices(new ApiSettings(true), new Scopes(), new ApiCredential(), new UserRows(), new SystemClock(), api_test_limiter(), $facts, new KitTestLinks());
$kit      = new ApiKit($services);
$ids      = static function (Credential $credential, array $wanted) use ($kit): array {
    $call    = new ApiCall(new Request('GET', 'v1/ext/acme/x', array(), array(), '127.0.0.1'), $credential);
    $context = $kit->listingContext($call);

    return array_column($kit->listingsById($call, $wanted, $context), 'id');
};
$public = Credential::anonymous(array(Scopes::PUBLIC_READ));
$owner  = new Credential(CredentialKind::KEY, array(Scopes::PUBLIC_READ), $seller);

harness_section('listingsById');
pin('in the order given, a missing id left out', array($second, $first), $ids($public, array($second, 999999, $first)));
pin('a hidden listing is left out for the public', array($first), $ids($public, array($pending, $first)));
pin('its owner sees it', array($pending, $first), $ids($owner, array($pending, $first)));
pin('repeats and bad ids are dropped', array($first), $ids($public, array($first, $first, 0, -3)));
pin('no ids, no query', array(), $ids($public, array()));

exit(harness_result());
