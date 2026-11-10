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
pin('the dashboard shows none, the bell lists them', '', $at(''));
pin('the orders list shows none', '', $at('billing'));
pin('billing settings show none', '', $at('settings', 'billing'));
pin('the listings screen shows none', '', $at('items'));
pin('general settings show none', '', $at('settings'));
pin('another plugin page shows none', '', $at('settings', 'custom', 'shipping'));
$own = $at('settings', 'custom', 'razorpay');
pin('a method\'s own settings page shows one notice', 1, substr_count($own, 'flashmessage-warning'));
check('it names both methods', str_contains($own, 'PayPal, Razorpay are in test mode'));
check('it links both settings pages', str_contains($own, 'PayPal settings') && str_contains($own, 'Razorpay settings'));
check('the links sit together in one block after the text', (bool) preg_match('~<span class="osc-pay-test-text">[^<]*<span class="osc-pay-test-links"><a [^>]*>PayPal settings</a> · <a ~u', $own));
check('it can be hidden', str_contains($own, 'data-osc-hide-cookie="' . OSC_PAY_TEST_COOKIE . '"'));
check('the other method\'s page shows it too', $at('settings', 'custom', 'paypal') !== '');

harness_section('hidden');
preg_match('/data-osc-hide-value="([0-9a-f]+)"/', $own, $m);
pin('hidden for this set of methods', '', osc_admin_payment_test_notice(array('page' => 'settings', 'action' => 'custom', 'id' => 'razorpay'), $m[1] ?? 'x'));
check('a stale hide does not hide it', osc_admin_payment_test_notice(array('page' => 'settings', 'action' => 'custom', 'id' => 'razorpay'), 'abc') !== '');

harness_section('a hostile name');
osc_add_filter('admin_payment_test_modes', static fn (array $modes): array => $modes + array('<img src=x onerror=alert(1)>' => 'https://example.test/oc-admin/index.php?page=settings&action=custom&id=evil'));
$evil = $at('settings', 'custom', 'evil');
check('the name is escaped', !str_contains($evil, '<img') && str_contains($evil, '&lt;img'));

exit(harness_result());
