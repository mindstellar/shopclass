<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/**
 * Tabs across the credits pages. Expects $billingTab: 'wallet', 'buy' or 'orders'.
 */

$tabs = array(
    'label'   => _m('Credits'),
    'items'   => array('wallet' => array(_m('Credits'), osc_billing_wallet_url())),
    'current' => $billingTab ?? '',
);
if (osc_billing_can_buy()) {
    $tabs['items']['buy'] = array(_m('Buy credits'), osc_billing_buy_url());
}
$tabs['items']['orders'] = array(_m('Orders'), osc_billing_orders_url());
require dirname(__DIR__) . '/parts/tabs.php';
