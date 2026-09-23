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
 * Two-sided contract for core page routing: the URLs core builds, and what the
 * rewrite table makes of them again.
 *
 * Build side pins every core osc_*_url() byte for byte with friendly URLs on and
 * off -- these strings live in sent emails and search indexes, so a changed one is
 * a broken link, not a refactor. Parse side feeds real URIs through the compiled
 * rules and pins the resolved parameters. Together they let the route definitions
 * move behind a shared table and stay provably the same.
 *
 * Quirks are pinned as they are, not as they should be: an empty secret still
 * leaves a trailing slash, and the search rule is the only one without /?.
 * Structures using {CATEGORIES} need a Category lookup and belong in the DB-backed
 * lane.  Usage:  php tests/core-routing.php
 */

define('WEB_PATH', 'http://example.com/');
define('OSC_DEBUG', false);
define('OC_ADMIN', false);
define('ABS_PATH', __DIR__ . '/no-such-webroot/');
define('REL_WEB_URL', '/');

$GLOBALS['__prefs'] = array();
$GLOBALS['__rw']    = false;

// Controlled-input stand-ins, defined before the real helpers load so there is no
// redeclaration: none of these live in the files required below.
function osc_get_preference($key, $section = 'osclass')
{
    return $GLOBALS['__prefs'][$key] ?? '';
}
function osc_rewrite_enabled()
{
    return (bool)$GLOBALS['__rw'];
}
function osc_item_id()
{
    return 42;
}
function osc_category_id()
{
    return 7;
}
function osc_alert_id()
{
    return 3;
}
function osc_alert_secret()
{
    return 'ALSEC';
}
function osc_user_email()
{
    return 'a b@example.com';
}

require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/routing/CoreRoutes.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Rewrite.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hDefines.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

// The shipped defaults (basic_data.sql). Paths an admin can rename are inputs, so
// the table is stated here rather than read from a database.
$GLOBALS['__prefs'] = array(
    'rewrite_item_url'                  => '{ITEM_TITLE}_i{ITEM_ID}',
    'rewrite_page_url'                  => '{PAGE_SLUG}-p{PAGE_ID}',
    'rewrite_cat_url'                   => '{CATEGORIES}',
    'rewrite_search_url'                => 'search',
    'rewrite_contact'                   => 'contact',
    'rewrite_feed'                      => 'feed',
    'rewrite_language'                  => 'language',
    'rewrite_item_mark'                 => 'item/mark',
    'rewrite_item_send_friend'          => 'item/send-friend',
    'rewrite_item_contact'              => 'item/contact',
    'rewrite_item_new'                  => 'item/new',
    'rewrite_item_activate'             => 'item/activate',
    'rewrite_item_edit'                 => 'item/edit',
    'rewrite_item_delete'               => 'item/delete',
    'rewrite_item_resource_delete'      => 'resource/delete',
    'rewrite_user_login'                => 'user/login',
    'rewrite_user_dashboard'            => 'user/dashboard',
    'rewrite_user_logout'               => 'user/logout',
    'rewrite_user_register'             => 'user/register',
    'rewrite_user_activate'             => 'user/activate',
    'rewrite_user_activate_alert'       => 'alert/confirm',
    'rewrite_user_profile'              => 'user/profile',
    'rewrite_user_items'                => 'user/items',
    'rewrite_user_alerts'               => 'user/alerts',
    'rewrite_billing_wallet'            => 'user/credits',
    'rewrite_billing_buy'               => 'user/credits/buy',
    'rewrite_billing_orders'            => 'user/orders',
    'rewrite_user_recover'              => 'user/recover',
    'rewrite_user_forgot'               => 'user/forgot',
    'rewrite_user_change_password'      => 'password/change',
    'rewrite_user_change_email'         => 'email/change',
    'rewrite_user_change_username'      => 'username/change',
    'rewrite_user_change_email_confirm' => 'email/confirm',
);

/* ------------------------------------------------------------------ build side */

$BUILD = array(
    'contact'                  => array('osc_contact_url', array()),
    'item post'                => array('osc_item_post_url', array()),
    'item post in category'    => array('osc_item_post_url_in_category', array()),
    'dashboard'                => array('osc_user_dashboard_url', array()),
    'logout'                   => array('osc_user_logout_url', array()),
    'login'                    => array('osc_user_login_url', array()),
    'register'                 => array('osc_register_account_url', array()),
    'account activate'         => array('osc_user_activate_url', array(5, 'CODE')),
    'resend activation'        => array('osc_user_resend_activation_link', array(5, 'a@b.c')),
    'alerts'                   => array('osc_user_alerts_url', array()),
    'alert activate'           => array('osc_user_activate_alert_url', array(3, 'SEC', 'a b@example.com')),
    'alert unsubscribe'        => array('osc_user_unsubscribe_alert_url', array()),
    'profile'                  => array('osc_user_profile_url', array()),
    'my listings'              => array('osc_user_list_items_url', array()),
    'my listings page'         => array('osc_user_list_items_url', array(2)),
    'my listings type'         => array('osc_user_list_items_url', array('', 'active')),
    'my listings page+type'    => array('osc_user_list_items_url', array(2, 'active')),
    'change email'             => array('osc_change_user_email_url', array()),
    'change username'          => array('osc_change_user_username_url', array()),
    'change email confirm'     => array('osc_change_user_email_confirm_url', array(5, 'CODE')),
    'change password'          => array('osc_change_user_password_url', array()),
    'recover password'         => array('osc_recover_user_password_url', array()),
    'forgot password confirm'  => array('osc_forgot_user_password_confirm_url', array(5, 'CODE')),
    'admin forgot confirm'     => array('osc_forgot_admin_password_confirm_url', array(1, 'CODE')),
    'change language'          => array('osc_change_language_url', array('es_ES')),
    'item edit'                => array('osc_item_edit_url', array('SEC', 42)),
    'item edit no secret'      => array('osc_item_edit_url', array('', 42)),
    'item delete'              => array('osc_item_delete_url', array('SEC', 42)),
    'item delete no secret'    => array('osc_item_delete_url', array('', 42)),
    'item activate'            => array('osc_item_activate_url', array('SEC', 42)),
    'item activate no secret'  => array('osc_item_activate_url', array('', 42)),
    'resource delete'          => array('osc_item_resource_delete_url', array(8, 42, 'CD', 'SEC')),
    'resource delete no secret' => array('osc_item_resource_delete_url', array(8, 42, 'CD')),
    'send to friend'           => array('osc_item_send_friend_url', array()),
    'item no friendly url'     => array('osc_item_url_ns', array(42, '')),
    'item no friendly url +locale' => array('osc_item_url_ns', array(42, 'es_ES')),
    'admin item edit'          => array('osc_item_admin_edit_url', array(42)),
);

$EXPECTED_OFF = array(
    'contact'                  => 'http://example.com/index.php?page=contact',
    'item post'                => 'http://example.com/index.php?page=item&action=item_add',
    'item post in category'    => 'http://example.com/index.php?page=item&action=item_add&catId=7',
    'dashboard'                => 'http://example.com/index.php?page=user&action=dashboard',
    'logout'                   => 'http://example.com/index.php?page=main&action=logout',
    'login'                    => 'http://example.com/index.php?page=login',
    'register'                 => 'http://example.com/index.php?page=register&action=register',
    'account activate'         => 'http://example.com/index.php?page=register&action=validate&id=5&code=CODE',
    'resend activation'        => 'http://example.com/index.php?page=login&action=resend&id=5&email=a@b.c',
    'alerts'                   => 'http://example.com/index.php?page=user&action=alerts',
    'alert activate'           => 'http://example.com/index.php?page=user&action=activate_alert'
        . '&email=a+b%40example.com&secret=SEC&id=3',
    'alert unsubscribe'        => 'http://example.com/index.php?page=user&action=unsub_alert'
        . '&email=a+b%40example.com&secret=ALSEC&id=3',
    'profile'                  => 'http://example.com/index.php?page=user&action=profile',
    'my listings'              => 'http://example.com/index.php?page=user&action=items',
    'my listings page'         => 'http://example.com/index.php?page=user&action=items&iPage=2',
    'my listings type'         => 'http://example.com/index.php?page=user&action=items&itemType=active',
    'my listings page+type'    => 'http://example.com/index.php?page=user&action=items&iPage=2&itemType=active',
    'change email'             => 'http://example.com/index.php?page=user&action=change_email',
    'change username'          => 'http://example.com/index.php?page=user&action=change_username',
    'change email confirm'     => 'http://example.com/index.php?page=user&action=change_email_confirm'
        . '&userId=5&code=CODE',
    'change password'          => 'http://example.com/index.php?page=user&action=change_password',
    'recover password'         => 'http://example.com/index.php?page=login&action=recover',
    'forgot password confirm'  => 'http://example.com/index.php?page=login&action=forgot&userId=5&code=CODE',
    'admin forgot confirm'     => 'http://example.com/oc-admin/index.php?page=login&action=forgot'
        . '&adminId=1&code=CODE',
    'change language'          => 'http://example.com/index.php?page=language&locale=es_ES',
    'item edit'                => 'http://example.com/index.php?page=item&action=item_edit&id=42&secret=SEC',
    'item edit no secret'      => 'http://example.com/index.php?page=item&action=item_edit&id=42',
    'item delete'              => 'http://example.com/index.php?page=item&action=item_delete&id=42&secret=SEC',
    'item delete no secret'    => 'http://example.com/index.php?page=item&action=item_delete&id=42',
    'item activate'            => 'http://example.com/index.php?page=item&action=activate&id=42&secret=SEC',
    'item activate no secret'  => 'http://example.com/index.php?page=item&action=activate&id=42',
    'resource delete'          => 'http://example.com/index.php?page=item&action=deleteResource'
        . '&id=8&item=42&code=CD&secret=SEC',
    'resource delete no secret' => 'http://example.com/index.php?page=item&action=deleteResource'
        . '&id=8&item=42&code=CD',
    'send to friend'           => 'http://example.com/index.php?page=item&action=send_friend&id=42',
    'item no friendly url'     => 'http://example.com/index.php?page=item&id=42',
    'item no friendly url +locale' => 'http://example.com/index.php?page=item&id=42&lang=es_ES',
    'admin item edit'          => 'http://example.com/oc-admin/index.php?page=items&action=item_edit&id=42',
);

// An admin URL and the two query-only helpers ignore the friendly-URL switch.
$EXPECTED_ON = array(
    'contact'                  => 'http://example.com/contact',
    'item post'                => 'http://example.com/item/new',
    'item post in category'    => 'http://example.com/item/new/7',
    'dashboard'                => 'http://example.com/user/dashboard',
    'logout'                   => 'http://example.com/user/logout',
    'login'                    => 'http://example.com/user/login',
    'register'                 => 'http://example.com/user/register',
    'account activate'         => 'http://example.com/user/activate/5/CODE',
    'resend activation'        => $EXPECTED_OFF['resend activation'],
    'alerts'                   => 'http://example.com/user/alerts',
    'alert activate'           => 'http://example.com/alert/confirm/3/SEC/a+b%40example.com',
    'alert unsubscribe'        => $EXPECTED_OFF['alert unsubscribe'],
    'profile'                  => 'http://example.com/user/profile',
    'my listings'              => 'http://example.com/user/items',
    'my listings page'         => 'http://example.com/user/items?iPage=2',
    'my listings type'         => 'http://example.com/user/items?itemType=active',
    'my listings page+type'    => 'http://example.com/user/items?iPage=2&itemType=active',
    'change email'             => 'http://example.com/email/change',
    'change username'          => 'http://example.com/username/change',
    'change email confirm'     => 'http://example.com/email/confirm/5/CODE',
    'change password'          => 'http://example.com/password/change',
    'recover password'         => 'http://example.com/user/recover',
    'forgot password confirm'  => 'http://example.com/user/forgot/5/CODE',
    'admin forgot confirm'     => $EXPECTED_OFF['admin forgot confirm'],
    'change language'          => 'http://example.com/language/es_ES',
    'item edit'                => 'http://example.com/item/edit/42/SEC',
    'item edit no secret'      => 'http://example.com/item/edit/42/',
    'item delete'              => 'http://example.com/item/delete/42/SEC',
    'item delete no secret'    => 'http://example.com/item/delete/42/',
    'item activate'            => 'http://example.com/item/activate/42/SEC',
    'item activate no secret'  => 'http://example.com/item/activate/42/',
    'resource delete'          => 'http://example.com/resource/delete/8/42/CD/SEC',
    'resource delete no secret' => 'http://example.com/resource/delete/8/42/CD',
    'send to friend'           => 'http://example.com/item/send-friend/42',
    'item no friendly url'     => $EXPECTED_OFF['item no friendly url'],
    'item no friendly url +locale' => $EXPECTED_OFF['item no friendly url +locale'],
    'admin item edit'          => $EXPECTED_OFF['admin item edit'],
);

foreach (array('off' => $EXPECTED_OFF, 'on' => $EXPECTED_ON) as $mode => $expected) {
    harness_section('URLs core builds, friendly URLs ' . $mode);
    $GLOBALS['__rw'] = ($mode === 'on');
    foreach ($BUILD as $label => $call) {
        pin($label, $expected[$label], call_user_func_array($call[0], $call[1]));
    }
}

/* -------------------------------------- routes reached through other helpers */

// hItems, hUsers, hBilling and hViews each delegate a one-line helper to the table.
// Those files pull in far more of core than a DB-free test can load, so the route
// each one names is pinned here directly.
$VIA_TABLE = array(
    'report spam'      => array('item_mark', array('as' => 'spam', 'id' => 42)),
    'report bad category' => array('item_mark', array('as' => 'badcat', 'id' => 42)),
    'public profile by name' => array('user_pub_profile', array('username' => 'jo')),
    'public profile by id' => array('user_pub_profile_id', array('id' => 5)),
    'credit wallet'    => array('billing_wallet', array()),
    'buy credit'       => array('billing_buy', array()),
    'orders'           => array('billing_orders', array()),
    'feature a listing' => array('billing_upgrade', array('itemId' => 42)),
    'feature a listing, named feature' => array('billing_upgrade', array('itemId' => 42, 'feature' => 'top ad')),
    'view beacon'      => array('item_view_beacon', array('id' => 42)),
    'export my data'   => array('user_export', array('id' => 5, 'secret' => 'a/b')),
    'delete my account' => array('user_delete', array()),
    'delete a comment' => array('item_delete_comment', array('id' => 42, 'comment' => 9)),
    'static page, query form' => array('page', array('id' => 3)),
    'static page, query form in locale' => array('page', array('id' => 3, 'lang' => 'es_ES')),
);

$VIA_OFF = array(
    'report spam'      => 'http://example.com/index.php?page=item&action=mark&as=spam&id=42',
    'report bad category' => 'http://example.com/index.php?page=item&action=mark&as=badcat&id=42',
    'public profile by name' => 'http://example.com/index.php?page=user&action=pub_profile&username=jo',
    'public profile by id' => 'http://example.com/index.php?page=user&action=pub_profile&id=5',
    'credit wallet'    => 'http://example.com/index.php?page=billing',
    'buy credit'       => 'http://example.com/index.php?page=billing&action=buy',
    'orders'           => 'http://example.com/index.php?page=billing&action=orders',
    'feature a listing' => 'http://example.com/index.php?page=billing&action=upgrade&itemId=42',
    'feature a listing, named feature' => 'http://example.com/index.php?page=billing&action=upgrade'
        . '&itemId=42&feature=top%20ad',
    'view beacon'      => 'http://example.com/index.php?page=item&action=view_beacon&id=42',
    'export my data'   => 'http://example.com/index.php?page=user&action=export&id=5&secret=a%2Fb',
    'delete my account' => 'http://example.com/index.php?page=user&action=delete',
    'delete a comment' => 'http://example.com/index.php?page=item&action=delete_comment&id=42&comment=9',
    'static page, query form' => 'http://example.com/index.php?page=page&id=3',
    'static page, query form in locale' => 'http://example.com/index.php?page=page&id=3&lang=es_ES',
);

$VIA_ON = array(
    'report spam'      => 'http://example.com/item/mark/spam/42',
    'report bad category' => 'http://example.com/item/mark/badcat/42',
    'public profile by name' => 'http://example.com/user/profile/jo',
    'public profile by id' => 'http://example.com/user/profile/5',
    'credit wallet'    => 'http://example.com/user/credits',
    'buy credit'       => 'http://example.com/user/credits/buy',
    'orders'           => 'http://example.com/user/orders',
    'feature a listing' => $VIA_OFF['feature a listing'],
    'feature a listing, named feature' => $VIA_OFF['feature a listing, named feature'],
    'view beacon'      => $VIA_OFF['view beacon'],
    'export my data'   => $VIA_OFF['export my data'],
    'delete my account' => $VIA_OFF['delete my account'],
    'delete a comment' => $VIA_OFF['delete a comment'],
    'static page, query form' => $VIA_OFF['static page, query form'],
    'static page, query form in locale' => $VIA_OFF['static page, query form in locale'],
);

foreach (array('off' => $VIA_OFF, 'on' => $VIA_ON) as $mode => $expected) {
    harness_section('routes other helpers delegate, friendly URLs ' . $mode);
    $GLOBALS['__rw'] = ($mode === 'on');
    foreach ($VIA_TABLE as $label => $call) {
        pin($label, $expected[$label], osc_core_url($call[0], $call[1]));
    }
}

/* ------------------------------------------------- permalink structures */

// The three structures an admin can rewrite. Both the pattern that matches an
// incoming URL and the link a theme prints come from the same row, so the pairs
// below are asserted together: change one and the other has to move with it.
use mindstellar\routing\CoreRoutes;

/** Swap one structure in, run $fn, put the table back. */
function withStructure(string $pref, string $value, callable $fn)
{
    $was = $GLOBALS['__prefs'][$pref];
    $GLOBALS['__prefs'][$pref] = $value;
    $out = $fn();
    $GLOBALS['__prefs'][$pref] = $was;

    return $out;
}

harness_section('listing permalinks');

pin(
    'the default structure builds a path',
    'blue-bike_i42',
    CoreRoutes::expand('item', array('ITEM_ID' => 42, 'ITEM_TITLE' => 'blue-bike'))
);
pin(
    'a structure with categories and a city fills all four',
    'vehicles/cars/pune/blue-bike_i42',
    withStructure('rewrite_item_url', '{CATEGORIES}/{ITEM_CITY}/{ITEM_TITLE}_i{ITEM_ID}', static function () {
        return CoreRoutes::expand('item', array(
            'ITEM_ID' => 42, 'ITEM_TITLE' => 'blue-bike',
            'ITEM_CITY' => 'pune', 'CATEGORIES' => 'vehicles/cars',
        ));
    })
);
// A '?' in a structure is stripped, not treated as a query separator.
pin(
    'a question mark in the structure is dropped',
    'blue-bikei42',
    withStructure('rewrite_item_url', '{ITEM_TITLE}?i{ITEM_ID}', static function () {
        return CoreRoutes::expand('item', array('ITEM_ID' => 42, 'ITEM_TITLE' => 'blue-bike'));
    })
);
pin(
    'the id is captured wherever it sits',
    array(
        '^([a-z]{2})_([A-Z]{2})/i([0-9]+)-.*\?comments-page=([0-9al]*)$'
            => 'index.php?page=item&id=$3&lang=$1_$2&comments-page=$4',
        '^i([0-9]+)-.*\?comments-page=([0-9al]*)$'
            => 'index.php?page=item&id=$1&comments-page=$2',
        '^([a-z]{2})_([A-Z]{2})/i([0-9]+)-.*$' => 'index.php?page=item&id=$3&lang=$1_$2',
        '^i([0-9]+)-.*$'                       => 'index.php?page=item&id=$1',
    ),
    withStructure('rewrite_item_url', 'i{ITEM_ID}-{ITEM_TITLE}', static function () {
        return CoreRoutes::templateRules('item');
    })
);

harness_section('page permalinks');

pin(
    'the default structure builds a path',
    'about-us-p3',
    CoreRoutes::expand('page', array('PAGE_ID' => 3, 'PAGE_SLUG' => 'about-us'))
);
pin(
    'a slug needing escaping is escaped',
    'sobre-n%C3%B3s-p3',
    CoreRoutes::expand('page', array('PAGE_ID' => 3, 'PAGE_SLUG' => 'sobre-nós'))
);
pin(
    'the default structure captures both id and slug',
    array(
        '^([\p{L}\p{N}_\-,]+)-p([0-9]+)/?$' => 'index.php?page=page&id=$2&slug=$1',
        '^([a-z]{2})_([A-Z]{2})/([\p{L}\p{N}_\-,]+)-p([0-9]+)/?$'
            => 'index.php?page=page&id=$4&slug=$3&lang=$1_$2',
    ),
    CoreRoutes::templateRules('page')
);
pin(
    'a structure with only the id captures only the id',
    array(
        '^page/([0-9]+)/?$' => 'index.php?page=page&id=$1',
        '^([a-z]{2})_([A-Z]{2})/page/([0-9]+)/?$' => 'index.php?page=page&id=$3&lang=$1_$2',
    ),
    withStructure('rewrite_page_url', 'page/{PAGE_ID}', static function () {
        return CoreRoutes::templateRules('page');
    })
);
pin(
    'a structure with only the slug captures only the slug',
    array(
        '^([\p{L}\p{N}_\-,]+)/?$' => 'index.php?page=page&slug=$1',
        '^([a-z]{2})_([A-Z]{2})/([\p{L}\p{N}_\-,]+)/?$'
            => 'index.php?page=page&slug=$3&lang=$1_$2',
    ),
    withStructure('rewrite_page_url', '{PAGE_SLUG}', static function () {
        return CoreRoutes::templateRules('page');
    })
);

harness_section('category permalinks');

pin(
    'the default structure is the whole slug path',
    'vehicles/cars',
    CoreRoutes::expand('category', array('CATEGORIES' => 'vehicles/cars'))
);
pin(
    'the older CATEGORY_SLUG spelling is still filled in',
    'cars-c7',
    withStructure('rewrite_cat_url', '{CATEGORY_SLUG}-c{CATEGORY_ID}', static function () {
        return CoreRoutes::expand('category', array('CATEGORY_SLUG' => 'cars', 'CATEGORY_ID' => 7));
    })
);
pin(
    'the default structure pages under itself',
    array(
        '^(.+)/([0-9]+)$' => 'index.php?page=search&sCategory=$1&iPage=$2',
        '^(.+)/?$'        => 'index.php?page=search&sCategory=$1',
    ),
    CoreRoutes::templateRules('category')
);
// The first placeholder present decides which capture answers sCategory. The page
// number is the capture after the structure's own, whatever it captured -- a
// two-part structure used to read its second capture as the page number.
pin(
    'a name-then-id structure resolves on the name, and pages after both',
    array(
        '^([^/]+)-c([0-9]+)/([0-9]+)$' => 'index.php?page=search&sCategory=$1&iPage=$3',
        '^([^/]+)-c([0-9]+)/?$'        => 'index.php?page=search&sCategory=$1',
    ),
    withStructure('rewrite_cat_url', '{CATEGORY_NAME}-c{CATEGORY_ID}', static function () {
        return CoreRoutes::templateRules('category');
    })
);

harness_section('structures that would answer the wrong page');

// An empty structure would compile to '^/?$' and answer the site's front page.
foreach (array('item' => 'rewrite_item_url', 'page' => 'rewrite_page_url',
    'category' => 'rewrite_cat_url') as $tpl => $pref) {
    pin(
        'an empty ' . $tpl . ' structure produces no rule at all',
        array(),
        withStructure($pref, '', static function () use ($tpl) {
            return CoreRoutes::templateRules($tpl);
        })
    );
    pin(
        'a whitespace-only ' . $tpl . ' structure produces no rule either',
        array(),
        withStructure($pref, '   ', static function () use ($tpl) {
            return CoreRoutes::templateRules($tpl);
        })
    );
}

// A placeholder written twice captures twice, so every later one shifts along.
pin(
    'a repeated placeholder does not misplace the ones after it',
    array(
        '^([0-9]+)/([\p{L}\p{N}_\-,]+)-([0-9]+)/?$' => 'index.php?page=page&id=$1&slug=$2',
        '^([a-z]{2})_([A-Z]{2})/([0-9]+)/([\p{L}\p{N}_\-,]+)-([0-9]+)/?$'
            => 'index.php?page=page&id=$3&slug=$4&lang=$1_$2',
    ),
    withStructure('rewrite_page_url', '{PAGE_ID}/{PAGE_SLUG}-{PAGE_ID}', static function () {
        return CoreRoutes::templateRules('page');
    })
);

harness_section('a value that would otherwise escape its parameter');

$GLOBALS['__rw'] = true;
pin(
    'a listing filter carrying a separator is encoded',
    'http://example.com/user/items?itemType=a%26b%3Dc',
    osc_core_url('user_items', array('itemType' => 'a&b=c'))
);
$GLOBALS['__rw'] = false;
pin(
    'and encoded with friendly URLs off too',
    'http://example.com/index.php?page=user&action=items&itemType=a%26b%3Dc',
    osc_core_url('user_items', array('itemType' => 'a&b=c'))
);

/* ------------------------------------------------------------------ parse side */

$REF = new ReflectionClass('Rewrite');
$rw  = $REF->newInstanceWithoutConstructor();
$rw->buildRules();
$RULES = $rw->listRules();

/** Run a URI through the compiled rules and return the parameters it resolves to. */
function resolve(string $uri): array
{
    global $REF, $RULES;
    $inst = $REF->newInstanceWithoutConstructor();
    $prop = $REF->getProperty('rules');
    $prop->setAccessible(true);
    $prop->setValue($inst, $RULES);

    $m = $REF->getMethod('resolveRewrite');
    $m->setAccessible(true);
    $out = $m->invoke($inst, $uri);
    $p   = $out['params'];
    ksort($p);

    return $p;
}

harness_section('what the rewrite table makes of a core URL again');

$PARSE = array(
    'contact'            => array('contact', array('page' => 'contact')),
    'rss feed'           => array('feed', array('page' => 'search', 'sFeed' => 'rss')),
    'named feed'         => array('feed/atom', array('page' => 'search', 'sFeed' => 'atom')),
    'language'           => array('language/es_ES', array('page' => 'language', 'locale' => 'es_ES')),
    'search'             => array('search', array('page' => 'search')),
    'search with params' => array('search/sCity,pune', array('page' => 'search', 'sParams' => 'sCity,pune')),
    'mark spam'          => array(
        'item/mark/spam/42',
        array('page' => 'item', 'action' => 'mark', 'as' => 'spam', 'id' => '42')
    ),
    'send to friend'     => array(
        'item/send-friend/42',
        array('page' => 'item', 'action' => 'send_friend', 'id' => '42')
    ),
    'contact seller'     => array('item/contact/42', array('page' => 'item', 'action' => 'contact', 'id' => '42')),
    'post a listing'     => array('item/new', array('page' => 'item', 'action' => 'item_add')),
    'post in category'   => array('item/new/7', array('page' => 'item', 'action' => 'item_add', 'catId' => '7')),
    'activate listing'   => array(
        'item/activate/42/SEC',
        array('page' => 'item', 'action' => 'activate', 'id' => '42', 'secret' => 'SEC')
    ),
    'edit listing'       => array(
        'item/edit/42/SEC',
        array('page' => 'item', 'action' => 'item_edit', 'id' => '42', 'secret' => 'SEC')
    ),
    'edit listing, empty secret' => array(
        'item/edit/42/',
        array('page' => 'item', 'action' => 'item_edit', 'id' => '42', 'secret' => '')
    ),
    'delete listing'     => array(
        'item/delete/42/SEC',
        array('page' => 'item', 'action' => 'item_delete', 'id' => '42', 'secret' => 'SEC')
    ),
    'delete a photo'     => array(
        'resource/delete/8/42/CD/SEC',
        array('page' => 'item', 'action' => 'deleteResource', 'id' => '8', 'item' => '42',
            'code' => 'CD', 'secret' => 'SEC')
    ),
    'delete a photo, no secret' => array(
        'resource/delete/8/42/CD',
        array('page' => 'item', 'action' => 'deleteResource', 'id' => '8', 'item' => '42',
            'code' => 'CD', 'secret' => '')
    ),
    'listing'            => array('blue-bike_i42', array('page' => 'item', 'id' => '42')),
    'listing in locale'  => array('es_ES/blue-bike_i42', array('page' => 'item', 'id' => '42', 'lang' => 'es_ES')),
    // The query string is cut off before the rules run, so comments-page never
    // comes from a rule -- it arrives as the ordinary GET parameter it already is.
    'listing, comments page in the query' => array(
        'blue-bike_i42?comments-page=2',
        array('page' => 'item', 'id' => '42')
    ),
    'login'              => array('user/login', array('page' => 'login')),
    'dashboard'          => array('user/dashboard', array('page' => 'user', 'action' => 'dashboard')),
    'logout'             => array('user/logout', array('page' => 'main', 'action' => 'logout')),
    'register'           => array('user/register', array('page' => 'register', 'action' => 'register')),
    'activate account'   => array(
        'user/activate/5/CODE',
        array('page' => 'register', 'action' => 'validate', 'id' => '5', 'code' => 'CODE')
    ),
    'activate alert'     => array(
        'alert/confirm/3/SEC/a%40b.c',
        array('page' => 'user', 'action' => 'activate_alert', 'id' => '3', 'secret' => 'SEC', 'email' => 'a@b.c')
    ),
    'own profile'        => array('user/profile', array('page' => 'user', 'action' => 'profile')),
    'public profile by id' => array(
        'user/profile/5',
        array('page' => 'user', 'action' => 'pub_profile', 'id' => '5')
    ),
    'public profile by id, paged' => array(
        'user/profile/5/2',
        array('page' => 'user', 'action' => 'pub_profile', 'id' => '5', 'iPage' => '2')
    ),
    'public profile by name' => array(
        'user/profile/jo',
        array('page' => 'user', 'action' => 'pub_profile', 'username' => 'jo')
    ),
    'my listings'        => array('user/items', array('page' => 'user', 'action' => 'items')),
    'my alerts'          => array('user/alerts', array('page' => 'user', 'action' => 'alerts')),
    'recover password'   => array('user/recover', array('page' => 'login', 'action' => 'recover')),
    'forgot password'    => array(
        'user/forgot/5/CODE',
        array('page' => 'login', 'action' => 'forgot', 'userId' => '5', 'code' => 'CODE')
    ),
    'change password'    => array('password/change', array('page' => 'user', 'action' => 'change_password')),
    'change email'       => array('email/change', array('page' => 'user', 'action' => 'change_email')),
    'change username'    => array('username/change', array('page' => 'user', 'action' => 'change_username')),
    'confirm new email'  => array(
        'email/confirm/5/CODE',
        array('page' => 'user', 'action' => 'change_email_confirm', 'userId' => '5', 'code' => 'CODE')
    ),
    'credit wallet'      => array('user/credits', array('page' => 'billing')),
    'buy credit'         => array('user/credits/buy', array('page' => 'billing', 'action' => 'buy')),
    'orders'             => array('user/orders', array('page' => 'billing', 'action' => 'orders')),
    'static page'        => array('about-us-p3', array('page' => 'page', 'id' => '3', 'slug' => 'about-us')),
    'static page in locale' => array(
        'es_ES/about-us-p3',
        array('page' => 'page', 'lang' => 'es_ES', 'id' => '3', 'slug' => 'about-us')
    ),
);

foreach ($PARSE as $label => $case) {
    $want = $case[1];
    ksort($want);
    pin($label . '  (' . $case[0] . ')', $want, resolve($case[0]));
}

exit(harness_result());
