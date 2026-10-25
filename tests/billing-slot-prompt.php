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
 * Pins the "Post another listing" box and the tabs on the credits pages, rendered
 * with stubbed helpers. DB-free.
 *
 * Usage:  php tests/billing-slot-prompt.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$S = array();
function osc_is_web_user_logged_in()
{
    global $S;
    return $S['in'];
}
function osc_billing_credits_enabled()
{
    global $S;
    return $S['on'];
}
function osc_logged_user_id()
{
    return 7;
}
function osc_user_can_publish()
{
    global $S;
    return $S['limit'] === -1 || $S['used'] < $S['limit'];
}
function osc_user_listing_limit($id = null)
{
    global $S;
    return $S['limit'];
}
function osc_user_listings_used($id = null)
{
    global $S;
    return $S['used'];
}
function osc_user_credits($id = null)
{
    global $S;
    return $S['credits'];
}
function osc_billing_next_listing_quote($id = null)
{
    global $S;
    return array(
        'slots'     => (int) max(0, ceil(($S['used'] - $S['limit'] + 1) / $S['qty'])),
        'credits'   => 0,
        'balance'   => $S['credits'],
        'shortfall' => 0,
    );
}
function osc_billing_slot_quantity()
{
    global $S;
    return $S['qty'];
}
function osc_billing_can_buy()
{
    global $S;
    return $S['canBuy'];
}
function osc_account_upgrade_offers($id)
{
    return array(array('feature' => 'listing.slot', 'label' => 'Extra listing slot', 'credits' => 5));
}
function osc_account_upgrade_url($f)
{
    return 'https://s.test/upgrade/' . $f;
}
function osc_billing_buy_url()
{
    return 'https://s.test/buy';
}
function osc_billing_wallet_url()
{
    return 'https://s.test/credits';
}
function osc_billing_orders_url()
{
    return 'https://s.test/orders';
}
function osc_csrf_token_form()
{
    return '';
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}
function _m($s)
{
    return $s;
}
function _mn($one, $many, $n)
{
    return $n === 1 ? $one : $many;
}

$render = static function (string $file, array $state, array $vars = array()): string {
    global $S;
    $S = $state + array('limit' => 3, 'used' => 3, 'credits' => 0, 'qty' => 1, 'canBuy' => true, 'in' => true, 'on' => true);
    extract($vars);
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/billing/' . $file;

    return (string) ob_get_clean();
};

harness_section('slot box');

check('hidden while the seller can post', $render('slot-prompt.php', array('used' => 2)) === '');
$box = $render('slot-prompt.php', array('credits' => 10));
check('at the limit with credits: buy and post', strpos($box, 'Use 5 credits and post') !== false && strpos($box, 'name="next" value="post"') !== false);
check('at the limit, no over-limit line', strpos($box, 'over your listing limit') === false);
$box = $render('slot-prompt.php', array());
check('at the limit without credits: buy credits', strpos($box, 'href="https://s.test/buy"') !== false);
check('without credits and nothing to buy: hidden', $render('slot-prompt.php', array('canBuy' => false)) === '');
check('exactly enough credits: buy and post', strpos($render('slot-prompt.php', array('credits' => 5)), 'Use 5 credits and post') !== false);
check('hidden when logged out', $render('slot-prompt.php', array('in' => false)) === '');
check('hidden with billing off', $render('slot-prompt.php', array('on' => false)) === '');
check('hidden with no limit', $render('slot-prompt.php', array('limit' => -1)) === '');

$box = $render('slot-prompt.php', array('used' => 5, 'credits' => 10));
check('2 over the limit: the box shows', strpos($box, 'oe-slot-prompt') !== false);
check('2 over the limit: needs 3 purchases', strpos($box, 'Buy this 3 times') !== false);
check('2 over the limit: one purchase does not go to the post form', strpos($box, 'Use 5 credits<') !== false && strpos($box, 'name="next"') === false);
$box = $render('slot-prompt.php', array('used' => 5, 'credits' => 10, 'qty' => 2));
check('2-slot purchases round up: 2 over the limit needs 2', strpos($box, 'Buy this 2 times') !== false);
$box = $render('slot-prompt.php', array('used' => 5, 'credits' => 10, 'qty' => 3));
check('a 3-slot purchase covers 2 over the limit', strpos($box, 'over your listing limit') === false && strpos($box, 'name="next" value="post"') !== false);

harness_section('credits tabs');

$tabs = $render('tabs.php', array(), array('billingTab' => 'orders'));
check('three tabs', substr_count($tabs, '<a ') === 3);
check('the current tab is marked', strpos($tabs, 'href="https://s.test/orders" aria-current="page"') !== false);
check('only one tab is current', substr_count($tabs, 'aria-current') === 1);
$tabs = $render('tabs.php', array('canBuy' => false), array('billingTab' => 'wallet'));
check('no Buy tab when nothing can be bought', strpos($tabs, 'https://s.test/buy') === false && substr_count($tabs, '<a ') === 2);
foreach (array('wallet', 'buy', 'orders') as $page) {
    $src = (string) file_get_contents(ABS_PATH . "oc-includes/osclass/gui/billing/{$page}-content.php");
    check("the {$page} page shows the tabs", strpos($src, "\$billingTab = '{$page}';") !== false && strpos($src, 'billing/tabs.php') !== false);
}

$items = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/gui/account/user-items-content.php');
check('My listings shows the limit message only without the slot box', strpos($items, "if (\$slotBox === '') { ?>") !== false);

exit(harness_result());
