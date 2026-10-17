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
 * GET seo through the in-process client: each kind of page answers, an unknown or hidden
 * listing, an unknown category or page and a missing id are refused, and friendly URLs off
 * and on give matching canonicals. DB-backed. Usage: php tests/api-seo.php
 */

define('WEB_PATH', 'http://seo.test/');
define('REL_WEB_URL', '/');
define('OC_ADMIN', false);
define('OSC_CSRF_SECRET', 'api-seo-test-secret');

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\api\InternalClient;

$admin = scratchdb_session('osc_api_seo');

foreach (['hLocale', 'hItems', 'hCategories', 'hApi'] as $helper) {
    if (is_file(ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php')) {
        require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
    }
}

function __($text, $domain = 'core')
{
    return $text;
}

function osc_plugins_path()
{
    return ABS_PATH . 'oc-content/plugins/';
}

function osc_is_web_user_logged_in()
{
    return false;
}

// hDefines.php cannot load beside the scratch stubs, so the URL helpers the answer uses are small stand-ins.
function osc_base_url($withIndex = false)
{
    return WEB_PATH . ($withIndex ? 'index.php' : '');
}

function osc_core_url($name, $args = [])
{
    return \mindstellar\routing\CoreRoutes::url($name, $args);
}

function osc_item_url_from_item($item, $locale = '')
{
    return osc_rewrite_enabled()
        ? WEB_PATH . ($locale !== '' ? $locale . '/' : '') . 'item/' . $item['pk_i_id']
        : osc_core_url('item', ['id' => $item['pk_i_id'], 'lang' => $locale]);
}

function osc_search_url($params = null)
{
    return WEB_PATH . 'search?' . http_build_query((array) $params);
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');

$motors  = seed_category($admin, 'Motors');
$cars    = seed_category($admin, 'Cars', $motors);
$live    = seed_item($admin, $cars, null, 'Red car');
$hidden  = seed_item($admin, $cars, null, 'Hidden car', 19.5, 1, 0);
$about   = seed_page($admin, 'about', 'About us');
$german  = seed_page($admin, 'imprint', 'Imprint', 1, 0, 'de_DE');
$system  = seed_page($admin, 'email_test', 'System mail', 0, 1);
Preference::newInstance()->replace('pageTitle', 'The Shop');
Preference::newInstance()->replace('language', 'en_US');

$client = InternalClient::site();
$get    = static fn (array $query): array => $client->call('GET', 'seo', $query);

harness_section('each kind');
$home = $get(['for' => 'home']);
pin('home answers', 200, $home['status']);
pin('home title is the site title', 'The Shop', $home['body']['data']['title'] ?? null);

$listing = $get(['for' => 'listing', 'id' => (string) $live]);
pin('listing answers', 200, $listing['status']);
pin('listing title', 'Red car - The Shop', $listing['body']['data']['title'] ?? null);
pin('listing breadcrumbs', ['Home', 'Motors', 'Cars', 'Red car'], array_column($listing['body']['data']['breadcrumbs'] ?? [], 'label'));

$category = $get(['for' => 'category', 'id' => 'cars']);
pin('category by slug answers', 200, $category['status']);
pin('category by id gives the same canonical', $category['body']['data']['canonical'] ?? null, $get(['for' => 'category', 'id' => (string) $cars])['body']['data']['canonical'] ?? '');

$page = $get(['for' => 'page', 'id' => 'about']);
pin('page answers', 200, $page['status']);
pin('page title', 'About us - The Shop', $page['body']['data']['title'] ?? null);
pin('page by id gives the same canonical', $page['body']['data']['canonical'] ?? null, $get(['for' => 'page', 'id' => (string) $about])['body']['data']['canonical'] ?? '');

$search = $get(['for' => 'search', 'q' => 'car']);
pin('search answers and is not indexed', ['noindex, follow', 200], [$search['body']['data']['robots'] ?? null, $search['status']]);

harness_section('refusals');
pin('unknown listing is 404', 404, $get(['for' => 'listing', 'id' => '99999'])['status']);
pin('hidden listing is 404', 404, $get(['for' => 'listing', 'id' => (string) $hidden])['status']);
pin('unknown category is 404', 404, $get(['for' => 'category', 'id' => 'nope'])['status']);
pin('unknown page is 404', 404, $get(['for' => 'page', 'id' => 'nope'])['status']);
pin('a system page is 404', 404, $get(['for' => 'page', 'id' => 'email_test'])['status']);
pin('a missing id is 422', 422, $get(['for' => 'listing'])['status']);
pin('an unknown kind is 422', 422, $get(['for' => 'cart'])['status']);
pin('an unknown locale is 422', 422, $get(['for' => 'home', 'locale' => 'xx_XX'])['status']);

harness_section('locale and friendly URLs');
$de = $get(['for' => 'page', 'id' => 'imprint', 'locale' => 'de_DE']);
pin('a German page keeps its language in the canonical', true, str_contains((string) ($de['body']['data']['canonical'] ?? ''), 'de_DE'));
$plain = $page['body']['data']['canonical'] ?? '';
check('without friendly URLs the page canonical is a query URL with the id', str_contains($plain, '?') && str_contains($plain, 'id=' . $about));

Preference::newInstance()->replace('rewriteEnabled', '1');
$friendly = $get(['for' => 'page', 'id' => 'about'])['body']['data']['canonical'] ?? '';
check('with friendly URLs the page canonical has no query string', $friendly !== '' && !str_contains($friendly, '?'));
check('both canonicals point at the same host', parse_url($friendly, PHP_URL_HOST) === parse_url($plain, PHP_URL_HOST));

exit(harness_result());
