<?php
/*
Plugin Name: Test Payments
Plugin URI: https://github.com/mindstellar/shopclass
Description: A payment gateway that moves no money. Buyers pick Pay, Decline, Leave pending or Fail on a test checkout page, and each drives the real billing callback. For testing only.
Version: 1.0.1
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: test-gateway
Requires Shopclass: 6.3.0
Tested up to: 6.4
Requires PHP: 8.0
*/

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\Orders;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\settings\SettingsPageRegistry;
use mindstellar\testgateway\TestGateway;

require_once __DIR__ . '/TestGateway.php';

/**
 * Nothing to install: every setting has a declared default.
 *
 * @return void
 */
function test_gateway_install()
{
}

osc_register_plugin(osc_plugin_path(__FILE__), 'test_gateway_install');

/**
 * Remove the stored settings.
 *
 * @return void
 */
function test_gateway_uninstall()
{
    foreach (array_keys(SettingsPageRegistry::instance()->fields(TestGateway::PAGE)) as $name) {
        osc_delete_preference($name, TestGateway::PAGE);
    }
}

osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', 'test_gateway_uninstall');

// 1) The settings page. Core owns the form, the save and the menu entry.
osc_register_settings_page(TestGateway::PAGE, require __DIR__ . '/settings.php');

osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(TestGateway::PAGE));
});

/**
 * Register the gateway while billing is on, as core does for its own.
 *
 * @return void
 */
function test_gateway_register()
{
    if (osc_billing_enabled()) {
        PaymentGatewayRegistry::instance()->register(new TestGateway());
    }
}

// 2) The gateway.
osc_add_hook('init', 'test_gateway_register');

// 3) The hosted checkout page and its buttons.
osc_add_route_hook('test-gateway-checkout', 'test-gateway/checkout/([0-9]+)', 'test-gateway/checkout/{order}');
osc_add_route_hook('test-gateway-pay', 'test-gateway/pay', 'test-gateway/pay');

/**
 * The buyer's own order on the test gateway, or a redirect away.
 *
 * @param int $orderId
 *
 * @return Order
 */
function test_gateway_buyer_order(int $orderId): Order
{
    if (!osc_is_web_user_logged_in()) {
        osc_redirect_to(osc_user_login_url());
    }

    $order = Orders::find($orderId);
    if (!TestGateway::enabled()
        || $order === null
        || $order->getUserId() !== (int) osc_logged_user_id()
        || $order->getGateway() !== TestGateway::ID
    ) {
        osc_add_flash_error_message(__('That test order is not available.', 'test-gateway'));
        osc_redirect_to(osc_billing_enabled() ? osc_billing_orders_url() : osc_base_url());
    }

    return $order;
}

osc_add_hook('test-gateway-checkout', static function () {
    $order = test_gateway_buyer_order(Params::getParamInt('order'));

    View::newInstance()->_exportVariableToView('testGatewayOrder', $order);
    osc_gui_view('', __DIR__ . '/checkout.php', array(
        'heading' => __('Test checkout', 'test-gateway'),
        'title'   => __('Test checkout', 'test-gateway') . ' - ' . osc_page_title(),
    ));
});

osc_add_hook('test-gateway-pay', static function () {
    if (Params::getServerParam('REQUEST_METHOD') !== 'POST') {
        osc_redirect_to(osc_base_url());
    }
    osc_csrf_check();

    $order   = test_gateway_buyer_order(Params::getParamInt('order'));
    $outcome = Params::getParamString('outcome');
    $back    = TestGateway::checkoutUrl($order->getId());
    $before  = $order->getStatus();

    if (!in_array($outcome, array('paid', 'declined', 'refunded', 'pending', 'error'), true)) {
        osc_redirect_to($back);
    }

    if (in_array($outcome, TestGateway::STATUSES, true)) {
        // The same call core's callback route makes with a provider's POST.
        Billing::handleCallback(TestGateway::ID, TestGateway::payload($order, $outcome));
    }

    TestGateway::flashOutcome($order->getId(), $outcome, $before);
    osc_redirect_to($outcome === 'error' ? $back : osc_billing_orders_url());
});

// 4) Never let it run unnoticed.
osc_add_hook('admin_page_header', static function () {
    if (!osc_settings_value(TestGateway::PAGE, 'enabled')) {
        return;
    }
    echo '<div class="flashmessage flashmessage-warning" role="status">'
        . osc_esc_html(__('Test payments are on: buyers can get credits without paying. Turn them off before the site goes live.', 'test-gateway'))
        . ' <a href="' . osc_esc_html(osc_settings_page_url(TestGateway::PAGE)) . '">'
        . osc_esc_html(__('Test payments settings', 'test-gateway')) . '</a></div>';
}, 10);

/* file end: ./oc-content/plugins/test-gateway/index.php */
