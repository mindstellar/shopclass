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
 * Payment methods in test mode share one notice, shown on the dashboard, the billing screens
 * and their own settings pages only, and hidden once the admin dismisses it for that set.
 * Usage: php tests/admin-payment-test-notice.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

$admin = api_admin_boot('osc_admin_pay_test_notice');
seed_locale($admin);
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';

$at = static fn (string $page, string $action = '', string $id = ''): string => osc_admin_payment_test_notice(array('page' => $page, 'action' => $action, 'id' => $id));

harness_section('nothing in test mode');
pin('no notice on the dashboard', '', $at(''));

harness_section('two methods in test mode');
osc_add_filter('admin_payment_test_modes', static fn (array $modes): array => $modes + array('PayPal' => 'https://example.test/oc-admin/index.php?page=settings&action=custom&id=paypal'));
osc_add_filter('admin_payment_test_modes', static fn (array $modes): array => $modes + array('Razorpay' => 'https://example.test/oc-admin/index.php?page=settings&action=custom&id=razorpay'));
$dash = $at('');
pin('the dashboard shows one notice', 1, substr_count($dash, 'flashmessage-warning'));
check('it names both methods', str_contains($dash, 'PayPal, Razorpay are in test mode'));
check('it links both settings pages', str_contains($dash, 'PayPal settings') && str_contains($dash, 'Razorpay settings'));
check('it can be hidden', str_contains($dash, 'data-osc-hide-cookie="' . OSC_PAY_TEST_COOKIE . '"'));
check('the orders list shows it', $at('billing') !== '');
check('billing settings show it', $at('settings', 'billing') !== '');
check('a method\'s own settings page shows it', $at('settings', 'custom', 'razorpay') !== '');
pin('the listings screen does not', '', $at('items'));
pin('general settings do not', '', $at('settings'));
pin('another plugin page does not', '', $at('settings', 'custom', 'shipping'));

harness_section('hidden');
preg_match('/data-osc-hide-value="([0-9a-f]+)"/', $dash, $m);
pin('hidden for this set of methods', '', osc_admin_payment_test_notice(array('page' => ''), $m[1] ?? 'x'));
check('a stale hide does not hide it', osc_admin_payment_test_notice(array('page' => ''), 'abc') !== '');

exit(harness_result());
