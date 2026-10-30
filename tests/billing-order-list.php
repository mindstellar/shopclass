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
foreach (['language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_offline_enabled' => '1', 'billing_offline_instructions' => 'Send it to IBAN GB29 NWBK 6016 1331 9268 19.'] as $k => $v) {
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
check('the amount reads like the orders page, in the currency symbol', str_contains($html, \mindstellar\currency\Money::display(33000000, '$')) && !str_contains($html, '33.00 USD'));
check('a bank transfer waits for payment, cash on delivery says so', str_contains($html, 'Awaiting payment') && str_contains($html, 'Pay on delivery') && !str_contains($html, 'Pending'));
check('the bank order shows how to pay', str_contains($html, 'Send it to IBAN ') && str_contains($html, 'Reference: '));
check('the IBAN and the reference can be copied', str_contains($html, '<code class="oe-copy-value">GB29 NWBK 6016 1331 9268 19</code>')
    && str_contains($html, '<code class="oe-copy-value">#' . $bank->getId() . '</code>') && str_contains($html, 'data-osc-copy="#' . $bank->getId() . '"'));
preg_match('#<td data-label="Payment method">(.*?)</td>#s', $html, $method);
check('the payment method cell holds only the method, so it does not wrap on a phone', isset($method[1]) && !str_contains($method[1], 'IBAN'));
check('how to pay sits in its own full-width row under the order', preg_match('#<tr class="oe-bill-howto">\s*<td colspan="5"[^>]*>.*?Send it to IBAN #s', $html) === 1);

harness_section('payment text');
require_once ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';
$text = osc_gui_payment_text("Pay <b>us</b>\nIBAN DE89370400440532013000, ref AB12 CD34");
check('the text is escaped and keeps its lines', str_contains($text, 'Pay &lt;b&gt;us&lt;/b&gt;<br>'));
check('an IBAN with no spaces is found', str_contains($text, '<code class="oe-copy-value">DE89370400440532013000</code>'));
check('a code too short for an IBAN is left as text', str_contains($text, 'ref AB12 CD34') && substr_count($text, 'oe-copy-value') === 1);
check('the Copy button waits for the script', str_contains($text, '" hidden>Copy</button>'));

harness_section('a list with a credits order');
$credits = OrderStore::create($tom, 'offline', 5000000, 'USD', 100, []);
$html    = $render([$bank, $credits]);
check('the Credits column is back', str_contains($html, 'Credits') && str_contains($html, '>100<'));

harness_section('a paid order');
OrderStore::settle($bank->getId(), \mindstellar\billing\Order::STATUS_PAID, 'ref-1');
$html = $render([OrderStore::find($bank->getId())]);
check('it says Paid and no longer shows payment details', str_contains($html, 'Paid') && !str_contains($html, 'IBAN'));
check('its paper is called Invoice, as on the orders page', str_contains($html, '>Invoice<') && !str_contains($html, 'Receipt'));

exit(harness_result());
