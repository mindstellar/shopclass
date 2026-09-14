<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the bundled test gateway (oc-content/plugins/test-gateway) against the real billing
 * layer. It hands out credits for nothing, so every check it skips is free money on any
 * site that forgets to turn it off:
 *
 *   - a callback while test mode is off does nothing
 *   - a bad, tampered, expired or malformed signature does nothing
 *   - a signed callback with the wrong amount or currency does nothing
 *   - a paid callback credits once, and a replay credits nothing more
 *   - a refund or decline for a different amount does nothing
 *
 * Usage:  php tests/test-gateway.php   (own scratch database, dropped on exit)
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_test_gateway');

// The same stand-ins tests/models/billing.php uses for hBilling.php.
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
function osc_plugins_path()
{
    return PLUGINS_PATH;
}
function osc_register_render_target($id, $path)
{
}
function osc_base_url($with_index = false)
{
    return WEB_PATH . ($with_index ? 'index.php' : '');
}
function _m($key)
{
    return $key;
}
require_once __DIR__ . '/lib/stubs.php';
function osc_route_url($id, $args = array())
{
    return WEB_PATH . 'index.php?page=route&route=' . $id . '&' . http_build_query($args);
}
$GLOBALS['flash'] = array();
function osc_add_flash_ok_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = 'ok';
}
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = 'error';
}
function osc_add_flash_info_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = 'info';
}
function osc_add_flash_warning_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flash'][] = 'warning';
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once PLUGINS_PATH . 'test-gateway/TestGateway.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\Orders;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;
use mindstellar\settings\SettingsPageRegistry;
use mindstellar\testgateway\TestGateway;

$setting = static function (string $name, string $value, string $type = 'STRING'): void {
    osc_set_preference($name, $value, TestGateway::PAGE, $type);
    osc_reset_preferences();
};
$status = static function (Order $order): string {
    return Orders::find($order->getId())->getStatus();
};
$ledgerRows = static function (Order $order) use ($admin): int {
    return (int) $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_billing_ledger WHERE s_ref_type = 'order' AND i_ref_id = "
        . $order->getId()
    )->fetch_assoc()['c'];
};

/* ----------------------------------------------------------------------------
 * The declared settings page.
 * ------------------------------------------------------------------------- */
harness_section('Settings page declaration');

$threw = null;
try {
    osc_register_settings_page(TestGateway::PAGE, require PLUGINS_PATH . 'test-gateway/settings.php');
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
pin('the declaration registers', null, $threw);

$fields = SettingsPageRegistry::instance()->fields(TestGateway::PAGE);
pin('test mode is off until an admin turns it on', false, TestGateway::setting('enabled'));
pin('the callback window defaults to 30 minutes', 30, TestGateway::setting('window'));
check(
    'auto_outcome is dropped unless mode is auto',
    !osc_settings_field_active(TestGateway::PAGE, 'auto_outcome', array('mode' => 'choose'))
    && osc_settings_field_active(TestGateway::PAGE, 'auto_outcome', array('mode' => 'auto'))
);
check('a malformed currency list is refused', osc_settings_validate($fields['currencies'], 'USD, EU') !== null);
pin('a well-formed currency list passes', null, osc_settings_validate($fields['currencies'], 'USD, EUR'));

/* ----------------------------------------------------------------------------
 * Fixtures: billing on, the gateway registered, one buyer.
 * ------------------------------------------------------------------------- */
osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

$gateway = new TestGateway();
PaymentGatewayRegistry::instance()->register($gateway);

$buyer = seed_user($admin, 'buyer', 'buyer@example.test');
$newOrder = static function (int $credits = 100) use ($buyer): Order {
    return Orders::create($buyer, TestGateway::ID, 9_990_000, 'USD', $credits);
};

/* ----------------------------------------------------------------------------
 * Test mode off.
 * ------------------------------------------------------------------------- */
harness_section('Refused while test mode is off');

$order  = $newOrder();
$result = Billing::handleCallback(TestGateway::ID, TestGateway::payload($order, 'paid'));
check('the gateway is not offered', !$gateway->isConfigured() && !isset(PaymentGatewayRegistry::instance()->available()[TestGateway::ID]));
pin('a valid callback is ignored', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('nothing is credited', 0, Wallet::balance($buyer));
pin('the order stays pending', Order::STATUS_PENDING, $status($order));

$setting('enabled', '1', 'BOOLEAN');
check('switching test mode on offers the gateway', isset(PaymentGatewayRegistry::instance()->available('USD')[TestGateway::ID]));
check('billing off takes it away again', (static function () {
    osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
    osc_reset_preferences();
    $off = !TestGateway::enabled();
    osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
    osc_reset_preferences();

    return $off;
})());
pin('the buyer sees it marked as a test', 'Test payment (Test)', $gateway->getName());

/* ----------------------------------------------------------------------------
 * Signatures.
 * ------------------------------------------------------------------------- */
harness_section('Signature, age and shape');

$payload        = TestGateway::payload($order, 'paid');
$forged         = $payload;
$forged['sig']  = str_repeat('0', 64);
$result         = Billing::handleCallback(TestGateway::ID, $forged);
pin('a wrong signature is ignored', 'bad signature', $result->getMessage());

$tampered          = $payload;
$tampered['order'] = (string) $newOrder()->getId();
$result            = Billing::handleCallback(TestGateway::ID, $tampered);
pin('a signature over another order is ignored', 'bad signature', $result->getMessage());

$window  = TestGateway::setting('window') * 60;
$expired = TestGateway::payload($order, 'paid', time() - $window - 5);
$result  = Billing::handleCallback(TestGateway::ID, $expired);
pin('an expired callback is ignored', 'callback expired', $result->getMessage());

$missing = $payload;
unset($missing['ref']);
pin('a callback missing a field is ignored', 'malformed callback', Billing::handleCallback(TestGateway::ID, $missing)->getMessage());

$arrayed           = $payload;
$arrayed['amount'] = array('9990000');
pin('an array where a field belongs is ignored', 'malformed callback', Billing::handleCallback(TestGateway::ID, $arrayed)->getMessage());

pin('none of them credited anything', 0, Wallet::balance($buyer));
pin('none of them settled the order', Order::STATUS_PENDING, $status($order));

/* ----------------------------------------------------------------------------
 * Money: a correctly signed callback for the wrong figures.
 * ------------------------------------------------------------------------- */
harness_section('Amount and currency');

$short           = array_merge($payload, array('amount' => '1'));
$short['sig']    = TestGateway::sign($short);
$result          = Billing::handleCallback(TestGateway::ID, $short);
pin('a short-paid callback is ignored', 'amount mismatch', $result->getMessage());

$euro            = array_merge($payload, array('currency' => 'EUR'));
$euro['sig']     = TestGateway::sign($euro);
$result          = Billing::handleCallback(TestGateway::ID, $euro);
pin('a wrong-currency callback is ignored', 'currency mismatch', $result->getMessage());

pin('neither credited anything', 0, Wallet::balance($buyer));
pin('the order is still pending', Order::STATUS_PENDING, $status($order));

/* ----------------------------------------------------------------------------
 * Success and replay.
 * ------------------------------------------------------------------------- */
harness_section('Paid once, replay credits nothing');

$result = Billing::handleCallback(TestGateway::ID, $payload);
pin('a valid paid callback settles', CallbackResult::OUTCOME_PAID, $result->getOutcome());
pin('the credits land', 100, Wallet::balance($buyer));
pin('the order is paid', Order::STATUS_PAID, $status($order));
pin('the provider reference is stored', $payload['ref'], Orders::find($order->getId())->getExternalRef());

Billing::handleCallback(TestGateway::ID, $payload);
Billing::handleCallback(TestGateway::ID, TestGateway::payload($order, 'paid'));
pin('a replay and a fresh paid callback credit nothing more', 100, Wallet::balance($buyer));
pin('one ledger row for the order', 1, $ledgerRows($order));

/* ----------------------------------------------------------------------------
 * Refund and decline.
 * ------------------------------------------------------------------------- */
harness_section('Refund and decline');

$refund        = TestGateway::payload($order, 'refunded');
$wrong         = array_merge($refund, array('amount' => '5'));
$wrong['sig']  = TestGateway::sign($wrong);
pin('a refund for another amount is ignored', 'order does not match', Billing::handleCallback(TestGateway::ID, $wrong)->getMessage());
pin('the order stays paid', Order::STATUS_PAID, $status($order));

$euroRefund        = array_merge($refund, array('currency' => 'EUR'));
$euroRefund['sig'] = TestGateway::sign($euroRefund);
pin('a refund in another currency is ignored', 'order does not match', Billing::handleCallback(TestGateway::ID, $euroRefund)->getMessage());
pin('the order is still paid', Order::STATUS_PAID, $status($order));

pin('a valid refund is accepted', CallbackResult::OUTCOME_REFUNDED, Billing::handleCallback(TestGateway::ID, $refund)->getOutcome());
pin('the credits are taken back', 0, Wallet::balance($buyer));
Billing::handleCallback(TestGateway::ID, $refund);
pin('a replayed refund takes nothing more', 0, Wallet::balance($buyer));
pin('the order is refunded', Order::STATUS_REFUNDED, $status($order));

$declined            = $newOrder(40);
$wrongDecline        = array_merge(TestGateway::payload($declined, 'declined'), array('amount' => '5'));
$wrongDecline['sig'] = TestGateway::sign($wrongDecline);
pin('a decline for another amount is ignored', 'order does not match', Billing::handleCallback(TestGateway::ID, $wrongDecline)->getMessage());
pin('the order stays pending', Order::STATUS_PENDING, $status($declined));
Billing::handleCallback(TestGateway::ID, TestGateway::payload($declined, 'declined'));
pin('a decline fails the order', Order::STATUS_FAILED, $status($declined));

/* A second press, or a second tab, must not report a success that already happened. */
$GLOBALS['flash'] = array();
TestGateway::flashOutcome($declined->getId(), 'declined', Order::STATUS_PENDING);
TestGateway::flashOutcome($declined->getId(), 'declined', Order::STATUS_FAILED);
pin('only the press that changed the order reports it', array('error', 'warning'), $GLOBALS['flash']);

/* ----------------------------------------------------------------------------
 * Checkout and currencies.
 * ------------------------------------------------------------------------- */
harness_section('Checkout and currencies');

$pending = $newOrder(25);
$intent  = $gateway->createCheckout($pending);
check(
    'choose mode sends the buyer to the test checkout page',
    $intent->getKind() === CheckoutIntent::KIND_REDIRECT
    && strpos($intent->getPayload(), 'route=test-gateway-checkout') !== false
    && strpos($intent->getPayload(), 'order=' . $pending->getId()) !== false
);
pin('and changes nothing yet', Order::STATUS_PENDING, $status($pending));

$setting('mode', 'auto');
$setting('auto_outcome', 'paid');
$gateway->createCheckout($pending);
pin('auto mode settles at once', Order::STATUS_PAID, $status($pending));
pin('auto mode credits the order', 25, Wallet::balance($buyer));

$setting('auto_outcome', 'pending');
$left = $newOrder(10);
$gateway->createCheckout($left);
pin('auto mode can leave an order pending', Order::STATUS_PENDING, $status($left));

pin('no currencies set means the billing currency', array('USD'), $gateway->getSupportedCurrencies());
$setting('currencies', 'eur, GBP, nope');
pin('listed codes are used and a bad one is skipped', array('EUR', 'GBP'), $gateway->getSupportedCurrencies());

exit(harness_result());
