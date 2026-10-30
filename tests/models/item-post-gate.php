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
 * The public post form at the listing limit: with pay-at-post a seller over the slot limit
 * writes the listing and is sent to the pay step; any other refusal still turns them back.
 *
 * Usage:  php tests/models/item-post-gate.php       (standalone, own scratch database)
 *         php tests/run-models.php item-post-gate   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

// URL helpers carry their arguments, so a redirect target can be pinned.
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = [])
    {
        return WEB_PATH . 'route/' . $name . ($args ? '?' . http_build_query($args) : '');
    }
}
if (!function_exists('osc_locate_template')) {
    function osc_locate_template($names, $fallback = '')
    {
        return $fallback;
    }
}
if (!function_exists('osc_item_post_url')) {
    function osc_item_post_url()
    {
        return osc_core_url('item_new');
    }
}
if (!function_exists('osc_user_list_items_url')) {
    function osc_user_list_items_url($page = '', $typeItem = '')
    {
        return osc_core_url('user_items');
    }
}
if (!function_exists('osc_search_category_url')) {
    function osc_search_category_url()
    {
        return osc_core_url('search_category');
    }
}

$_SESSION = [];
$admin = api_admin_boot('osc_models_item_post_gate');
scratchdb_pin_site_mode($admin, 'classifieds');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hMessages.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
if (!function_exists('_mn')) {
    function _mn($single, $plural, $count)
    {
        return $count === 1 ? $single : $plural;
    }
}

use mindstellar\billing\Billing;

$p  = DB_TABLE_PREFIX;
$en = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$root = seed_category($admin, 'Vehicles', null, $en);
$cat  = seed_category($admin, 'Cars', $root, $en, 1, 30);
$sue  = seed_user($admin, 'sue', 'sue@example.test');
$adm  = api_admin_seed_admin($admin, 'gateadmin');
seed_item($admin, $cat, $sue, 'Live one');
foreach ([Billing::PREF_ENABLED => 'BOOLEAN', 'billing_slot_enabled' => 'BOOLEAN'] as $k => $t) {
    osc_set_preference($k, '1', $k === Billing::PREF_ENABLED ? Billing::PREF_GROUP : 'osclass', $t);
}
foreach (['billing_free_live_listings' => '1', 'billing_slot_credits' => '3', 'billing_slot_quantity' => '1', 'moderate_items' => '-1', 'items_wait_time' => '0'] as $k => $v) {
    osc_set_preference($k, $v, 'osclass', 'INTEGER');
}
osc_reset_preferences();
osc_register_billing_slot();
$_SERVER['REMOTE_ADDR'] = '192.0.2.81';
Session::getInstance()->_set('gate', 1);

/** Thrown in place of the exit() a real redirect ends the request with. */
class GateRedirect extends RuntimeException
{
}

/** The real controller, with the view and the redirect recorded instead of taken. */
class TestGateItem extends CWebItem
{
    public function doView($file)
    {
    }

    public function redirectTo($url, $code = null)
    {
        throw new GateRedirect((string) $url);
    }
}

/** Sign in as a user (or nobody), or as an admin. */
$signIn = static function (?int $userId, ?int $adminId = null) use ($admin, $p): void {
    $view = View::getInstance();
    $view->_erase('_loggedUser');
    $session = Session::getInstance();
    foreach (['userId', 'userEmail', 'userName', 'adminId', 'adminStamp'] as $k) {
        $session->_drop($k);
    }
    if ($userId !== null) {
        $view->_exportVariableToView('_loggedUser', User::getInstance()->findByPrimaryKey($userId));
        osc_is_web_user_logged_in();
    }
    if ($adminId !== null) {
        $session->_set('adminId', $adminId);
        $session->_set('adminStamp', 0);
    }
};

$fields = static fn (array $extra = []): array => $extra + [
    'catId'        => (string) $cat,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'address'      => '1 Main Street',
    'title'        => ['en_US' => 'Red hatchback'],
    'description'  => ['en_US' => 'A small red car, one owner, full service history.'],
    'price'        => '1500',
    'contactName'  => 'Sue',
    'contactEmail' => 'sue@example.test',
    'contactPhone' => '5550199',
];

/** One request to the post form; what it exported, where it redirected and what it flashed. */
$drive = static function (string $action, array $post = []) use ($signIn): array {
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = $_REQUEST = ['page' => 'item', 'action' => $action];
    $_POST = $post === [] ? [] : $post + ['CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue()];
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    Params::init();
    View::getInstance()->_erase('listingPostQuote');
    Session::getInstance()->_dropMessage('pubMessages');

    $redirect = null;
    try {
        // Without the constructor: it boots the theme, which a model test has no use for.
        $web = (new ReflectionClass('TestGateItem'))->newInstanceWithoutConstructor();
        foreach (['page' => 'item', 'action' => $action, 'itemManager' => Item::getInstance(), 'user' => osc_is_web_user_logged_in() ? User::getInstance()->findByPrimaryKey(osc_logged_user_id()) : null] as $name => $value) {
            $prop = new ReflectionProperty(property_exists('CWebItem', $name) ? 'CWebItem' : 'BaseModel', $name);
            $prop->setAccessible(true);
            $prop->setValue($web, $value);
        }
        $web->doModel();
    } catch (GateRedirect $e) {
        $redirect = $e->getMessage();
    }
    $flashes = Session::getInstance()->_getMessage('pubMessages');
    $quote   = View::getInstance()->_exists('listingPostQuote') ? View::getInstance()->_get('listingPostQuote') : null;

    return [
        'redirect' => $redirect,
        'quote'    => $quote,
        'flashes'  => is_array($flashes) ? array_map(static fn ($f) => $f['type'] . ':' . $f['msg'], $flashes) : [],
    ];
};
$flashed = static fn (array $r, string $needle): bool => (bool) array_filter($r['flashes'], static fn ($f) => str_contains($f, $needle));

harness_section('opening the form at the limit');
$signIn($sue);
$open = $drive('item_add');
pin('the switch on lets the seller in', null, $open['redirect']);
pin('the form is told what the listing costs', 3, $open['quote']['credits'] ?? null);
check('the notice says so', $flashed($open, 'This listing will cost 3 credits. You pay on the last step.'));
check('the form knows the post is held', osc_item_post_held());
pin('so the submit button says Save and pay', 'Save and pay', osc_item_post_submit_label());

osc_set_preference('billing_pay_at_post', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();
$off = $drive('item_add');
pin('the switch off sends the seller to My listings', osc_core_url('user_items'), $off['redirect']);
check('with the limit message', $flashed($off, 'You are at your listing limit'));

osc_set_preference('billing_pay_at_post', '1', 'osclass', 'BOOLEAN');
osc_reset_preferences();
$refuse = static fn ($allowed) => false;
osc_add_filter('billing_can_publish', $refuse);
$blocked = $drive('item_add');
pin('a billing_can_publish refusal still turns the seller back', osc_core_url('user_items'), $blocked['redirect']);
osc_remove_filter('billing_can_publish', $refuse);

$signIn(null, $adm);
$asAdmin = $drive('item_add');
pin('an admin is never metered', null, $asAdmin['redirect']);
pin('and gets no cost notice', [], $asAdmin['flashes']);
check('nor a held post', !osc_item_post_held());
pin('so the submit button says Publish', 'Publish', osc_item_post_submit_label());

harness_section('posting at the limit');
$redirects = 0;
$count     = static function ($url) use (&$redirects) {
    $redirects++;

    return $url;
};
osc_add_filter('item_post_redirect_url', $count);

$signIn($sue);
$posted = $drive('item_add_post', $fields());
$held   = (int) $admin->query("SELECT pk_i_id FROM {$p}t_item WHERE fk_i_user_id = $sue AND dt_first_pub_date IS NULL")->fetch_row()[0];
check('the listing was saved held', $held > 0);
pin('the seller goes to the pay step', osc_core_url('billing_publish', ['itemId' => $held]), $posted['redirect']);
check('the saved notice is flashed', $flashed($posted, 'Your listing is saved. Pay for it on the next page to publish it.'));
check('no published notice', !$flashed($posted, 'Your listing has been published'));
pin('item_post_redirect_url never saw the held listing', 0, $redirects);

harness_section('the held listing\'s own page');
Session::getInstance()->_dropMessage('pubMessages');
$notice = new ReflectionMethod('CWebItem', 'flashStatusNotice');
$notice->setAccessible(true);
$heldRow = Item::getInstance()->findByPrimaryKey($held);
$notice->invoke((new ReflectionClass('TestGateItem'))->newInstanceWithoutConstructor(), $heldRow);
$note = (array) (Session::getInstance()->_getMessage('pubMessages')[0] ?? []);
pin('the owner gets an info notice, not a warning', 'info', $note['type'] ?? null);
check('that cannot be dismissed', ($note['dismissible'] ?? true) === false);
check('with the deadline', str_contains((string) ($note['msg'] ?? ''), 'If it is not paid for by ' . osc_format_date((string) $heldRow['dt_expiration'])));
check('and the pay link', str_contains((string) ($note['msg'] ?? ''), osc_esc_html(osc_billing_publish_url($held))));
Session::getInstance()->_dropMessage('pubMessages');

harness_section('posting within the limit');
$signIn(seed_user($admin, 'bob', 'bob@example.test'));
$within = $drive('item_add_post', $fields(['contactEmail' => 'bob@example.test']));
pin('a seller in quota goes to the category page', osc_core_url('search_category'), $within['redirect']);
check('with the published notice', $flashed($within, 'Your listing has been published'));
pin('item_post_redirect_url saw it once', 1, $redirects);

exit(harness_result());
