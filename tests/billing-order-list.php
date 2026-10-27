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
 * The shared order list: a shop order shows its number, what was bought and a plain status
 * word, a list with no credits order has no Credits column, and a pending bank transfer order
 * shows how to pay.
 * Usage: php tests/billing-order-list.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_billing_order_list');

seed_locale($admin);
seed_currency($admin);
$admin->query("UPDATE " . DB_TABLE_PREFIX . "t_currency SET s_description = '$'");
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_offline_enabled' => '1', 'billing_offline_instructions' => 'Send it to IBAN 123.'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$render = static function (array $orders): string {
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/billing/parts/order-list.php';

    return (string) ob_get_clean();
};

$goods = ['_kind' => 'goods', '_label' => 'Mug and 2 more'];
$bank  = OrderStore::create($tom, 'offline', 33000000, 'USD', 0, $goods);
$cod   = OrderStore::create($tom, 'cod', 1000000, 'USD', 0, $goods);

harness_section('a list of shop orders');
$html = $render([$bank, $cod]);
check('it shows the order number and what was bought', str_contains($html, '#' . $bank->getId()) && str_contains($html, 'Mug and 2 more'));
check('it has no Credits column', !str_contains($html, 'Credits'));
check('the amount reads like a price', str_contains($html, '33.00 USD'));
check('a bank transfer waits for payment, cash on delivery says so', str_contains($html, 'Awaiting payment') && str_contains($html, 'Pay on delivery') && !str_contains($html, 'Pending'));
check('the bank order shows how to pay', str_contains($html, 'Send it to IBAN 123.') && str_contains($html, 'Reference: #' . $bank->getId()));

harness_section('a list with a credits order');
$credits = OrderStore::create($tom, 'offline', 5000000, 'USD', 100, []);
$html    = $render([$bank, $credits]);
check('the Credits column is back', str_contains($html, 'Credits') && str_contains($html, '>100<'));

harness_section('a paid order');
OrderStore::settle($bank->getId(), \mindstellar\billing\Order::STATUS_PAID, 'ref-1');
$html = $render([OrderStore::find($bank->getId())]);
check('it says Paid and no longer shows payment details', str_contains($html, 'Paid') && !str_contains($html, 'IBAN'));

exit(harness_result());
