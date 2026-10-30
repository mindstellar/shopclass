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
 * The My listings row for a held listing: the "Waiting for payment" badge, the Publish link and no
 * upgrade links, only in the seller's own list. DB-free. Usage: php tests/listing-row-unpaid.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';

function osc_plugins_path()
{
    return ABS_PATH . 'oc-content/plugins/';
}

function _m($text)
{
    return $text;
}

function _mn($single, $plural, $count)
{
    return (int) $count === 1 ? $single : $plural;
}

function osc_billing_next_listing_quote(?int $userId = null): array
{
    return $GLOBALS['quote'];
}

function osc_billing_publish_url(int $itemId): string
{
    return 'http://x.test/?page=billing&action=publish&itemId=' . $itemId;
}

function osc_logged_user_id()
{
    return $GLOBALS['me'] ?? 0;
}

function osc_format_date($date, $format = '')
{
    return substr((string) $date, 0, 10);
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';

// hBilling.php registers the two listeners at load; the test loads hItems.php alone, so it does the same.
$billing = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php');
foreach (['listing_row_badges' => 'osc_listing_row_unpaid_badge', 'listing_row_actions' => 'osc_listing_row_unpaid_actions'] as $hook => $fn) {
    check("hBilling.php registers $fn on $hook", str_contains($billing, "osc_add_filter('$hook', '$fn');"));
    osc_add_filter($hook, $fn);
}

$GLOBALS['quote'] = ['slots' => 1, 'credits' => 3, 'balance' => 0, 'shortfall' => 3];

$held = ['pk_i_id' => '7', 'b_enabled' => '0', 'b_active' => '0', 'dt_first_pub_date' => null];
$live = ['pk_i_id' => '8', 'b_enabled' => '1', 'b_active' => '1', 'dt_first_pub_date' => '2026-01-01 00:00:00'];
$off  = ['pk_i_id' => '9', 'b_enabled' => '0', 'b_active' => '1', 'dt_first_pub_date' => '2026-01-01 00:00:00'];

$badges = ['status' => ['label' => 'Blocked', 'class' => 'cancelled']];
$acts   = [
    'edit'            => ['label' => 'Edit', 'url' => 'e'],
    'upgrade_premium' => ['label' => 'Feature', 'url' => 'u', 'group' => 'promote'],
    'delete'          => ['label' => 'Delete', 'url' => 'd'],
];

harness_section('Badge');
$out = osc_apply_filter('listing_row_badges', $badges, $held, 'user_items');
pin('a held row reads Waiting for payment', 'Waiting for payment', $out['status']['label']);
pin('...in the pending look', 'pending', $out['status']['class']);
pin('without a deadline there is no delete date', false, isset($out['deadline']));
$out = osc_apply_filter('listing_row_badges', $badges, $held + ['dt_expiration' => '2026-11-06 10:00:00'], 'user_items');
pin('with one it says when it is deleted', ['Deleted on 2026-11-06 if not paid', 'refunded'], [$out['deadline']['label'] ?? null, $out['deadline']['class'] ?? null]);
pin('a live row keeps its badges', $badges, osc_apply_filter('listing_row_badges', $badges, $live, 'user_items'));
pin('a blocked row keeps its badges', $badges, osc_apply_filter('listing_row_badges', $badges, $off, 'user_items'));
pin('another list keeps its badges', $badges, osc_apply_filter('listing_row_badges', $badges, $held, 'public_profile'));

harness_section('Actions');
$out = osc_apply_filter('listing_row_actions', $acts, $held, 'user_items');
pin('Publish comes first with its credits', 'Publish (3 credits)', $out['publish']['label']);
pin('...and links to the pay step', 'http://x.test/?page=billing&action=publish&itemId=7', $out['publish']['url']);
check('...and is the row\'s primary action', !empty($out['publish']['primary']) && str_contains((string) $out['publish']['class'], 'oe-btn--primary'));
pin('edit and delete stay', ['publish', 'edit', 'delete'], array_keys($out));
pin('a live row keeps its actions', $acts, osc_apply_filter('listing_row_actions', $acts, $live, 'user_items'));
pin('another list keeps its actions', $acts, osc_apply_filter('listing_row_actions', $acts, $held, 'public_profile'));

harness_section('osc_item_is_unpaid');
View::newInstance()->_exportVariableToView('item', $held);
check('true for a held item', osc_item_is_unpaid());
View::newInstance()->_exportVariableToView('item', $live);
check('false for a live item', !osc_item_is_unpaid());

harness_section('osc_item_pay_url');
View::newInstance()->_exportVariableToView('item', $held + ['fk_i_user_id' => '5']);
$GLOBALS['me'] = 5;
pin('the owner of a held item gets the pay step', 'http://x.test/?page=billing&action=publish&itemId=7', osc_item_pay_url());
$GLOBALS['me'] = 6;
pin('anyone else gets nothing', '', osc_item_pay_url());
$GLOBALS['me'] = 0;
pin('nor does a guest', '', osc_item_pay_url());
View::newInstance()->_exportVariableToView('item', $live + ['fk_i_user_id' => '5']);
$GLOBALS['me'] = 5;
pin('a live item has nothing to pay', '', osc_item_pay_url());

exit(harness_result());

/* file end: ./tests/listing-row-unpaid.php */
