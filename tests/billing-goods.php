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
 * Goods orders: the cart pricer reads every price and stock from the database whatever the
 * request carries, caps quantities and lines, turns bad lines into problems, runs the cart
 * filters in order and keeps the total at zero or more; GoodsCheckout::place() writes the
 * order and its lines in one transaction and cancels the order when the gateway gives no
 * checkout; the shipped, delivered and cancelled transitions; bought(); the export; and
 * migration 0080 building what struct.sql builds.
 * Usage: php tests/billing-goods.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CartPricer;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\exception\InvalidException;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_billing_goods');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat   = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue   = seed_user($admin, 'sue', 'sue@example.test');
$tom   = seed_user($admin, 'tom', 'tom@example.test');
$admin->query("UPDATE {$p}t_user SET s_phone_mobile = '5550100' WHERE pk_i_id = $tom");
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

final class GoodsFakeGateway implements PaymentGateway
{
    public bool $throws = false;
    public int $calls   = 0;

    public function getId(): string
    {
        return 'fake';
    }

    public function getName(): string
    {
        return 'Fake';
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD'];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        $this->calls++;
        if ($this->throws) {
            throw new RuntimeException('provider down');
        }

        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('none');
    }
}
$gateway = new GoodsFakeGateway();
PaymentGatewayRegistry::getInstance()->register($gateway);

$m      = static fn (float $units): int => (int) round($units * 1000000);
$tee    = seed_item($admin, $cat, $sue, 'Tee', 699.0);
$mug    = seed_item($admin, $cat, $sue, 'Mug', 9.0);
$hidden = seed_item($admin, $cat, $sue, 'Hidden', 9.0, 0);
$noPay  = seed_item($admin, $cat, $sue, 'No price', 9.0);
$euro   = seed_item($admin, $cat, $sue, 'Euro mug', 9.0);
$admin->query("UPDATE {$p}t_item SET i_price = NULL WHERE pk_i_id = $noPay");
$admin->query("UPDATE {$p}t_item SET fk_c_currency_code = 'EUR' WHERE pk_i_id = $euro");
$photo = seed_photo($admin, $tee);
$row   = static fn (string $size, ?int $stock, ?int $price): array => ['options' => ['Size' => $size], 'fields' => ['i_stock' => $stock, 'i_price' => $price, 's_sku' => 'TEE-' . $size]];
[$s, $md, $l] = VariantStore::saveSet($tee, [$row('S', 0, $m(699)), $row('M', 3, $m(699)), $row('L', null, $m(799))]);
$mugV    = VariantStore::saveDefault($mug, ['i_stock' => null]);
$hiddenV = VariantStore::saveDefault($hidden, ['i_price' => $m(5)]);
$noPayV  = VariantStore::saveDefault($noPay, []);
$euroV   = VariantStore::saveDefault($euro, []);

$pricer = new CartPricer();
$quote  = static fn (array $lines, array $ctx = []) => $pricer->quote($lines, $ctx);

harness_section('the quote prices from the database');
$q = $quote([['variant' => $md, 'qty' => 2, 'price' => 1, 'unit' => 1, 'amount' => 1]]);
pin('unit and amount come from the variant row', [$m(699), $m(1398)], [$q->lines()[0]['unit'], $q->lines()[0]['amount']]);
pin('the subtotal too', $m(1398), $q->totals()['subtotal']);
pin('in the listing currency', 'USD', $q->currency());
pin('the title, options, sku and listing photo', ['Tee', ['Size' => 'M'], 'TEE-M', $photo], [$q->lines()[0]['title'], $q->lines()[0]['options'], $q->lines()[0]['sku'], (int) $q->lines()[0]['listing']['photo']['pk_i_id']]);
$q = $quote([['variant' => $l, 'qty' => 2]]);
pin('a variant\'s own price beats the listing price', [$m(799), $m(1598)], [$q->lines()[0]['unit'], $q->lines()[0]['amount']]);
$q = $quote([['variant' => $mugV, 'qty' => 1]]);
pin('a variant with no price sells at the listing price', $m(9), $q->lines()[0]['unit']);
pin('untracked stock is available and shows no count', [true, null], [$q->lines()[0]['available'], $q->lines()[0]['stock_left']]);
pin('a string id and quantity are read', [$md, 3], [$quote([['variant' => (string) $md, 'qty' => '3']])->lines()[0]['variant'], $quote([['variant' => (string) $md, 'qty' => '3']])->lines()[0]['qty']]);

harness_section('caps and problems');
$q = $quote([['variant' => $md, 'qty' => 25]]);
pin('a quantity over 20 is capped with a problem', [20, [['line' => 0, 'code' => 'qty', 'pointer' => null, 'message' => null]]], [$q->lines()[0]['qty'], array_values(array_filter($q->problems(), static fn ($x) => $x['code'] === 'qty'))]);
$q = $quote([['variant' => $l, 'qty' => 0]]);
pin('a quantity under 1 becomes 1 with a problem', [1, [['line' => 0, 'code' => 'qty', 'pointer' => null, 'message' => null]]], [$q->lines()[0]['qty'], $q->problems()]);
$q = $quote([['variant' => $l, 'qty' => 2], ['variant' => $l, 'qty' => 3]]);
pin('the same variant twice is one line', [1, 5], [count($q->lines()), $q->lines()[0]['qty']]);
$many = [];
for ($i = 0; $i < 51; $i++) {
    $many[] = ['variant' => 900000 + $i, 'qty' => 1];
}
$q = $quote($many);
pin('the 51st line is refused for the cap', ['line' => 50, 'code' => 'qty', 'pointer' => null, 'message' => null], $q->problems()[50] ?? null);
pin('every other unknown variant is not buyable', 50, count(array_filter($q->problems(), static fn ($x) => $x['code'] === 'not_buyable')));
$q = $quote([['variant' => $hiddenV, 'qty' => 1], ['variant' => 999999, 'qty' => 1], ['variant' => $noPayV, 'qty' => 1], ['variant' => 'x'], 'junk', ['variant' => $md, 'qty' => 1]]);
pin('hidden, unknown, priceless and malformed lines are problems, not errors', [
    ['line' => 0, 'code' => 'not_buyable', 'pointer' => null, 'message' => null], ['line' => 1, 'code' => 'not_buyable', 'pointer' => null, 'message' => null], ['line' => 2, 'code' => 'not_buyable', 'pointer' => null, 'message' => null],
    ['line' => 3, 'code' => 'not_buyable', 'pointer' => null, 'message' => null], ['line' => 4, 'code' => 'not_buyable', 'pointer' => null, 'message' => null],
], $q->problems());
pin('and the good line is still priced', [1, $m(699)], [count($q->lines()), $q->total()]);
$q = $quote([['variant' => $md, 'qty' => 1], ['variant' => $euroV, 'qty' => 1]]);
$otherCurrency = 'This item is priced in EUR and your cart is in USD. Check out or empty your cart first.';
pin('a line in another currency is not buyable, and says why', [['line' => 1, 'code' => 'not_buyable', 'pointer' => null, 'message' => $otherCurrency]], $q->problems());
$q = $quote([['variant' => $euroV, 'qty' => 1], ['variant' => $md, 'qty' => 1]]);
pin('a new line in another currency, put first, is the one refused; the site currency stays', ['USD', [$md], [['line' => 0, 'code' => 'not_buyable', 'pointer' => null, 'message' => $otherCurrency]]], [$q->currency(), array_column($q->lines(), 'variant'), $q->problems()]);
pin('a cart all in another currency is in that currency', ['EUR', []], [$quote([['variant' => $euroV, 'qty' => 1]])->currency(), $quote([['variant' => $euroV, 'qty' => 1]])->problems()]);
$q = $quote([['variant' => $s, 'qty' => 1], ['variant' => $md, 'qty' => 4]]);
pin('lines short of stock are out of stock', [['line' => 0, 'code' => 'out_of_stock', 'pointer' => null, 'message' => null], ['line' => 1, 'code' => 'out_of_stock', 'pointer' => null, 'message' => null]], $q->problems());
pin('with what is left', [0, 3], [$q->lines()[0]['stock_left'], $q->lines()[1]['stock_left']]);
pin('and they are not in the subtotal', 0, $q->totals()['subtotal']);
pin('an empty cart quotes zero in the site currency', [0, 'USD', []], [$quote([])->total(), $quote([])->currency(), $quote([])->lines()]);

harness_section('core delivery');
Preference::getInstance()->set('billing_shipping_flat', (string) $m(49));
Preference::getInstance()->set('billing_free_over', (string) $m(999));
scratchdb_forget_cache();
osc_reset_preferences();
$q = $quote([['variant' => $mugV, 'qty' => 1]]);
pin('standard delivery is offered at the flat amount', [['id' => 'standard', 'label' => 'Standard delivery', 'eta' => null, 'amount' => $m(49)]], $q->shippingMethods());
pin('and chosen when none is asked', [$m(49), $m(58)], [$q->totals()['shipping'], $q->total()]);
pin('with the free-delivery meter', ['amount' => $m(999), 'remaining' => $m(990)], $q->freeOver());
pin('one shipping line', [['id' => 'shipping.standard', 'label' => 'Standard delivery', 'amount' => $m(49), 'currency' => 'USD', 'meta' => ['method' => 'standard']]], $q->extraLines('shipping'));
$q = $quote([['variant' => $md, 'qty' => 2]]);
pin('free over the amount', [0, $m(1398)], [$q->totals()['shipping'], $q->total()]);
$q = $quote([['variant' => $mugV, 'qty' => 1]], ['shipping_method' => 'teleport']);
pin('a method not offered falls back to the first one offered, and says so', ['standard', $m(49), $m(58), 'standard'], [$q->shipping()['id'] ?? null, $q->totals()['shipping'], $q->total(), $q->cart()['shipping_method']]);

harness_section('the cart filters');
$seen    = [];
$express = static function ($methods, $cart) use (&$seen, $m) {
    $seen[]    = 'methods';
    $methods[] = ['id' => 'express', 'label' => 'Express', 'eta' => 'Tomorrow', 'amount' => $m(99)];
    $methods[] = ['id' => 'Bad Id', 'label' => 'x', 'amount' => 1];
    $methods[] = ['id' => 'euro', 'label' => 'Euro', 'amount' => 1, 'currency' => 'EUR'];

    return $methods;
};
$shipLines = static function ($lines, $cart) use (&$seen) {
    $seen[] = 'shipping:' . $cart['shipping_method'];

    return $lines;
};
$tax = static function ($lines, $cart) use (&$seen, $m) {
    $seen[]  = 'tax:' . count($cart['shipping_lines']) . ':' . count($cart['discount_lines'] ?? []);
    $lines[] = ['id' => 'gst', 'label' => 'GST', 'amount' => $m(10), 'meta' => ['rate' => 18]];
    $lines[] = ['label' => 'Wrong currency', 'amount' => $m(1), 'currency' => 'EUR'];
    $lines[] = ['label' => 'Not a number', 'amount' => 'lots'];

    return $lines;
};
$discount = static function ($lines, $cart) use (&$seen, $m) {
    $seen[]  = 'discount';
    $lines[] = ['id' => 'promo', 'label' => 'Promo', 'amount' => $m(5)];

    return $lines;
};
$fee = static function ($totals, $cart) use (&$seen, $m) {
    $seen[]           = 'totals:' . $totals['total'];
    $totals['fees'][] = ['id' => 'cod', 'label' => 'Cash fee', 'amount' => $m(2)];
    $totals['total']  = 1;
    $totals['subtotal'] = 1;

    return $totals;
};
osc_add_filter('cart_shipping_methods', $express, 10);
osc_add_filter('cart_shipping_lines', $shipLines);
osc_add_filter('cart_tax_lines', $tax);
osc_add_filter('cart_discount_lines', $discount);
osc_add_filter('cart_totals', $fee);
$q = $quote([['variant' => $mugV, 'qty' => 1]], ['shipping_method' => 'express']);
pin('the filters run in order, each seeing the lines before it; tax sees the discounts', ['methods', 'shipping:express', 'discount', 'tax:1:1', 'totals:' . $m(9 + 99 + 10 - 5)], $seen);
pin('a malformed method is dropped', ['standard', 'express'], array_column($q->shippingMethods(), 'id'));
pin('the chosen method prices the shipping line', $m(99), $q->totals()['shipping']);
pin('a bad tax line is dropped', [['id' => 'gst', 'label' => 'GST', 'amount' => $m(10), 'currency' => 'USD', 'meta' => ['rate' => 18], 'included' => false]], $q->extraLines('tax'));
pin('a discount always takes off', [-$m(5), $m(5)], [$q->extraLines('discount')[0]['amount'], $q->totals()['discount']]);
pin('cart_totals adds a fee, and its own sums are ignored', ['subtotal' => $m(9), 'shipping' => $m(99), 'discount' => $m(5), 'tax' => $m(10), 'tax_included' => 0, 'fee' => $m(2), 'total' => $m(115)], $q->totals());
$huge = static function ($lines) {
    $lines[] = ['label' => 'Everything free', 'amount' => -9000000000000];

    return $lines;
};
osc_add_filter('cart_discount_lines', $huge);
pin('a discount bigger than the cart leaves a total of zero', 0, $quote([['variant' => $mugV, 'qty' => 1]], ['shipping_method' => 'express'])->total());
osc_remove_filter('cart_discount_lines', $huge);
$noExpress = static fn ($methods) => array_values(array_filter($methods, static fn ($x) => $x['id'] !== 'express'));
osc_add_filter('cart_shipping_methods', $noExpress, 20);
$q = $quote([['variant' => $mugV, 'qty' => 1]], ['shipping_method' => 'express']);
pin('express asked where it is not offered prices the first method, never free delivery', ['standard', $m(49), [$m(49)]], [$q->shipping()['id'] ?? null, $q->totals()['shipping'], array_column($q->extraLines('shipping'), 'amount')]);
osc_remove_filter('cart_shipping_methods', $noExpress);
foreach (['cart_shipping_methods' => $express, 'cart_shipping_lines' => $shipLines, 'cart_tax_lines' => $tax, 'cart_discount_lines' => $discount, 'cart_totals' => $fee] as $hook => $fn) {
    osc_remove_filter($hook, $fn);
}
$negative = static fn ($lines) => array_merge($lines, [['label' => 'Minus', 'amount' => -$m(5)]]);
$negativeFee = static function ($totals) use ($m) {
    $totals['fees'][] = ['label' => 'Minus fee', 'amount' => -$m(5)];

    return $totals;
};
foreach (['cart_shipping_lines', 'cart_tax_lines'] as $hook) {
    osc_add_filter($hook, $negative);
}
osc_add_filter('cart_totals', $negativeFee);
$q = $quote([['variant' => $mugV, 'qty' => 1]]);
pin('a shipping, tax or fee line below zero is dropped', [[], [], $m(9 + 49)], [$q->extraLines('tax'), $q->extraLines('fee'), $q->total()]);
pin('so only the standard shipping line is left', ['shipping.standard'], array_column($q->extraLines('shipping'), 'id'));
foreach (['cart_shipping_lines', 'cart_tax_lines'] as $hook) {
    osc_remove_filter($hook, $negative);
}
osc_remove_filter('cart_totals', $negativeFee);

$badMethods = static function ($methods) use ($m) {
    $methods[] = ['id' => 'pickup', 'label' => 'Pickup', 'amount' => $m(3)];
    $methods[] = ['id' => 'refund', 'label' => 'Refund', 'amount' => -$m(5)];
    $methods[] = ['id' => 'pickup', 'label' => 'Second pickup', 'amount' => $m(1)];
    $methods[] = ['id' => 'blank', 'label' => '  ', 'amount' => $m(1)];

    return $methods;
};
osc_add_filter('cart_shipping_methods', $badMethods, 10);
$q = $quote([['variant' => $mugV, 'qty' => 1]]);
pin('an old callback\'s negative, duplicate or unlabelled method is dropped', [['standard', 'Standard delivery', $m(49)], ['pickup', 'Pickup', $m(3)]], array_map(static fn ($x) => [$x['id'], $x['label'], $x['amount']], $q->shippingMethods()));
osc_remove_filter('cart_shipping_methods', $badMethods);

harness_section('the quote reads three times');
check('the query counter works here', harness_questions() >= 0);
$count = harness_query_count(static fn () => $quote([['variant' => $md, 'qty' => 1], ['variant' => $mugV, 'qty' => 1], ['variant' => $l, 'qty' => 1]]));
pin('variants, listings and photos', $count < 0 ? $count : 3, $count);

harness_section('placing an order');
$address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];
$checkout = new GoodsCheckout();
$fired    = [];
$onBefore = static function ($cart) use (&$fired) {
    $fired[] = ['before', count($cart['lines'])];
};
$onPlaced = static function ($orderId, $userId) use (&$fired) {
    $fired[] = ['placed', $orderId, $userId];
};
$onCancel = static function ($orderId, $userId) use (&$fired) {
    $fired[] = ['cancelled', $orderId, $userId];
};
osc_add_hook('cart_checkout_before', $onBefore);
osc_add_hook('billing_order_placed', $onPlaced);
osc_add_hook('billing_order_cancelled', $onCancel);
$orders = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0];
$lineRows = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order_line")->fetch_row()[0];
$refused = static function (callable $fn): array {
    try {
        $fn();
    } catch (InvalidException $e) {
        return array_map(static fn (array $x): string => $x['pointer'] . ' ' . $x['code'], $e->errors());
    }

    return ['not refused'];
};

$placed = $checkout->place($tom, [['variant' => $md, 'qty' => 2, 'price' => 1], ['variant' => $mugV, 'qty' => 1]], $address + ['extra' => 'x'], 'standard', 'fake');
$order  = OrderStore::find($placed['order']->getId());
pin('the order is pending at the server total', [Order::STATUS_PENDING, $m(1398 + 9), 'USD', 0, 'fake'], [$order->getStatus(), $order->getAmount(), $order->getCurrency(), $order->getCredits(), $order->getGateway()]);
pin('it is a goods order with a label', [true, 'Tee × 2 and 1 more'], [$order->isGoods(), $order->getLabel()]);
pin('the address is kept trimmed to its fields', $address, $order->meta('_address'));
pin('the buyer comes from the account', ['email' => 'tom@example.test', 'name' => 'tom', 'phone' => '5550100'], $order->meta('_buyer'));
pin('the shipping method is recorded', ['method' => 'standard', 'label' => 'Standard delivery'], $order->meta('_shipping'));
pin('the next step is the gateway\'s', 'https://pay.example.test/' . $order->getId(), $placed['next']->getPayload());
$lines = OrderLineStore::ofOrder($order->getId());
pin('lines: the goods, then shipping', [['item', $tee, $md, 'Tee', 2, $m(699), $m(1398), 'TEE-M'], ['item', $mug, $mugV, 'Mug', 1, $m(9), $m(9), null], ['shipping', null, null, 'Standard delivery', 1, 0, 0, null]], array_map(static fn ($x) => [$x['kind'], $x['item_id'], $x['variant_id'], $x['title'], $x['qty'], $x['unit'], $x['amount'], $x['sku']], $lines));
pin('options and meta read back', [['Size' => 'M'], ['id' => 'shipping.standard', 'method' => 'standard']], [$lines[0]['options'], $lines[2]['meta']]);
pin('itemLines() leaves out shipping', 2, count(OrderLineStore::itemLines($order->getId())));
pin('osc_order_lines() reads them', 3, count(osc_order_lines($order->getId())));
pin('the hooks fired once each', [['before', 2], ['placed', $order->getId(), $tom]], $fired);
pin('the fulfilment reads the address', ['standard', 'Austin', null], [$order->fulfilment()['method'], $order->fulfilment()['address']['city'], $order->fulfilment()['shipped_at']]);
$placedL = $checkout->place($tom, [['variant' => $l, 'qty' => 1, 'price' => 1, 'unit' => 1]], $address, 'standard', 'fake');
$linesL  = OrderLineStore::itemLines($placedL['order']->getId());
pin('an order for a pricier variant is charged its own price', [$m(799 + 49), $m(799), $m(799)], [OrderStore::find($placedL['order']->getId())->getAmount(), $linesL[0]['unit'], $linesL[0]['amount']]);
OrderStore::cancel($placedL['order']->getId());

$before = [$orders(), $lineRows()];
pin('a line short of stock refuses with its path', ['/lines/0 out_of_stock'], $refused(static fn () => $checkout->place($tom, [['variant' => $md, 'qty' => 4]], $address, 'standard', 'fake')));
pin('an unknown method refuses', ['/shipping_method unknown'], $refused(static fn () => $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'teleport', 'fake')));
pin('no method refuses', ['/shipping_method unknown'], $refused(static fn () => $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, '', 'fake')));
pin('an unknown gateway refuses', ['/gateway unavailable'], $refused(static fn () => $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'nope')));
pin('missing address fields refuse', ['/address/name required', '/address/postcode required'], $refused(static fn () => $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], ['name' => ' '] + array_diff_key($address, ['postcode' => 1]), 'standard', 'fake')));
pin('an empty cart refuses', ['/lines empty'], $refused(static fn () => $checkout->place($tom, [], $address, 'standard', 'fake')));
pin('a hidden listing refuses', ['/lines/0 not_buyable'], $refused(static fn () => $checkout->place($tom, [['variant' => $hiddenV, 'qty' => 1]], $address, 'standard', 'fake')));
pin('an unknown account refuses', [' unknown_user'], $refused(static fn () => $checkout->place(0, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake')));
pin('and none of them wrote anything', $before, [$orders(), $lineRows()]);

$fired = [];
$gateway->throws = true;
pin('a gateway that gives no checkout refuses', ['/gateway unavailable'], $refused(static fn () => $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake')));
$gateway->throws = false;
$failed = (int) $admin->query("SELECT MAX(pk_i_id) FROM {$p}t_billing_order")->fetch_row()[0];
pin('and leaves the order cancelled, not pending', Order::STATUS_CANCELLED, OrderStore::find($failed)->getStatus());
pin('announced as cancelled, never placed', [['before', 1], ['cancelled', $failed, $tom]], $fired);
pin('and marked so no cancel mail goes for an order the buyer never saw', true, OrderStore::find($failed)->meta('_mail_cancelled') !== null);

$admin->query("RENAME TABLE {$p}t_billing_order_line TO {$p}t_billing_order_line_off");
$before = $orders();
$threw  = false;
try {
    $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake');
} catch (Throwable $e) {
    $threw = true;
}
$admin->query("RENAME TABLE {$p}t_billing_order_line_off TO {$p}t_billing_order_line");
pin('lines that cannot be written roll the order back too', [true, $before], [$threw, $orders()]);

Preference::getInstance()->set('billing_enabled', '0');
scratchdb_forget_cache();
osc_reset_preferences();
pin('billing off refuses', ['/gateway unavailable'], $refused(static fn () => $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake')));
Preference::getInstance()->set('billing_enabled', '1');
scratchdb_forget_cache();
osc_reset_preferences();
foreach (['cart_checkout_before' => $onBefore, 'billing_order_placed' => $onPlaced, 'billing_order_cancelled' => $onCancel] as $hook => $fn) {
    osc_remove_hook($hook, $fn);
}

harness_section('fulfilment');
$moves = [];
foreach (['billing_order_shipped', 'billing_order_delivered', 'billing_order_cancelled'] as $hook) {
    osc_add_hook($hook, static function ($orderId, $userId) use (&$moves, $hook) {
        $moves[] = [$hook, $orderId, $userId];
    });
}
$id = $order->getId();
pin('a pending order cannot ship', false, OrderStore::ship($id, 'DHL', '123', 'https://track.example.test/123'));
pin('or be delivered', false, OrderStore::deliver($id));
check('it settles as paid with no credits', Billing::markPaid($order, 'ext-goods-1') && OrderStore::find($id)->isPaid());
pin('a paid order cannot be cancelled', false, OrderStore::cancel($id));
pin('a paid order ships', true, OrderStore::ship($id, ' DHL ', 'AB123', 'https://track.example.test/AB123'));
$shipped = OrderStore::find($id);
pin('shipped is still paid', [Order::STATUS_SHIPPED, true], [$shipped->getStatus(), $shipped->isPaid()]);
pin('with the parcel on the order and the method kept', ['standard', 'DHL', 'AB123', 'https://track.example.test/AB123', true], [
    $shipped->fulfilment()['method'], $shipped->fulfilment()['carrier'], $shipped->fulfilment()['tracking'], $shipped->fulfilment()['tracking_url'], $shipped->fulfilment()['shipped_at'] !== null,
]);
pin('the address stays', 'Austin', $shipped->meta('_address')['city']);
pin('it cannot ship twice', false, OrderStore::ship($id, 'DHL', 'X', 'https://x.example.test/'));
pin('it is delivered', [true, Order::STATUS_DELIVERED, true], [OrderStore::deliver($id), OrderStore::find($id)->getStatus(), OrderStore::find($id)->isPaid()]);
pin('once', false, OrderStore::deliver($id));
pin('with the date', true, OrderStore::find($id)->fulfilment()['delivered_at'] !== null);

$second = OrderStore::create($tom, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
OrderLineStore::add($second->getId(), [['kind' => 'item', 'item_id' => $hidden, 'variant_id' => $hiddenV, 'title' => 'Hidden', 'qty' => 1, 'unit' => $m(9), 'amount' => $m(9)]]);
pin('a pending order cancels', [true, Order::STATUS_CANCELLED], [OrderStore::cancel($second->getId()), OrderStore::find($second->getId())->getStatus()]);
pin('once', false, OrderStore::cancel($second->getId()));
pin('each move announced once with the buyer', [['billing_order_shipped', $id, $tom], ['billing_order_delivered', $id, $tom], ['billing_order_cancelled', $second->getId(), $tom]], $moves);

$third = OrderStore::create($tom, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
OrderLineStore::add($third->getId(), [['kind' => 'item', 'item_id' => $tee, 'variant_id' => $l, 'title' => 'Tee', 'qty' => 1, 'unit' => $m(9), 'amount' => $m(9)]]);
Billing::markPaid($third);
OrderStore::ship($third->getId(), 'DHL', '9', 'https://t.example.test/9');
pin('a shipped order refunds', [true, Order::STATUS_REFUNDED], [Billing::refund(OrderStore::find($third->getId())), OrderStore::find($third->getId())->getStatus()]);

harness_section('cash on delivery ships before it is paid');
$cod = OrderStore::create($tom, 'cod', $m(9), 'USD', 0, ['_kind' => 'goods']);
OrderLineStore::add($cod->getId(), [['kind' => 'item', 'item_id' => $euro, 'variant_id' => $euroV, 'title' => 'Euro mug', 'qty' => 1, 'unit' => $m(9), 'amount' => $m(9)]]);
$card = OrderStore::create($tom, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
$toShip = array_map(static fn (Order $o): int => $o->getId(), OrderStore::search(['to_ship' => true, 'goods' => true, 'user_id' => $tom], 100));
pin('To ship lists a pending cash on delivery order, not a pending card one', [true, false], [in_array($cod->getId(), $toShip, true), in_array($card->getId(), $toShip, true)]);
pin('and counts the same', count($toShip), OrderStore::searchCount(['to_ship' => true, 'goods' => true, 'user_id' => $tom]));
pin('a pending card order cannot ship', false, OrderStore::ship($card->getId(), 'DHL', '1', ''));
pin('a pending cash on delivery order ships', true, OrderStore::ship($cod->getId(), 'DHL', 'C1', ''));
$codNow = OrderStore::find($cod->getId());
pin('on its way it is not paid, and the buyer cannot cancel it', [Order::STATUS_SHIPPED, false, true, false], [$codNow->getStatus(), $codNow->isPaid(), $codNow->awaitsCash(), $codNow->buyerMayCancel()]);
pin('it is no purchase yet', false, OrderLineStore::bought($tom, $euro));
pin('the release job does not see it', false, in_array($cod->getId(), OrderStore::heldBefore(Billing::HOLDS_STOCK, date('Y-m-d H:i:s', time() + 86400), 100), true));
pin('delivered, it is paid', [true, Order::STATUS_DELIVERED, true, true], [Billing::deliver($codNow), OrderStore::find($cod->getId())->getStatus(), OrderStore::find($cod->getId())->isPaid(), OrderStore::find($cod->getId())->getPaidDate() !== null]);
pin('and a purchase', true, OrderLineStore::bought($tom, $euro));
$refusedCod = OrderStore::create($tom, 'cod', $m(9), 'USD', 0, ['_kind' => 'goods']);
OrderStore::ship($refusedCod->getId(), 'DHL', 'C2', '');
pin('a buyer cancel checked while pending does not cancel it once shipped', [false, Order::STATUS_SHIPPED], [OrderStore::cancel($refusedCod->getId(), Order::STATUS_PENDING), OrderStore::find($refusedCod->getId())->getStatus()]);
pin('a refused parcel can be cancelled', [true, Order::STATUS_CANCELLED], [OrderStore::cancel($refusedCod->getId()), OrderStore::find($refusedCod->getId())->getStatus()]);
$paidCod = OrderStore::create($tom, 'cod', $m(9), 'USD', 0, ['_kind' => 'goods']);
Billing::markPaid($paidCod);
OrderStore::ship($paidCod->getId(), 'DHL', 'C3', '');
pin('one marked paid first ships as paid and is not cancelled', [true, false], [OrderStore::find($paidCod->getId())->isPaid(), OrderStore::cancel($paidCod->getId())]);
OrderStore::cancel($card->getId());

harness_section('who bought what');
$pending = OrderStore::create($sue, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
OrderLineStore::add($pending->getId(), [['item_id' => $mug, 'variant_id' => $mugV, 'title' => 'Mug', 'unit' => $m(9), 'amount' => $m(9)]]);
pin('a pending order is not a purchase', false, osc_order_bought($sue, $mug));
pin('a cancelled one is not', false, OrderLineStore::bought($tom, $hidden));
pin('a delivered one is', [true, true], [OrderLineStore::bought($tom, $tee), osc_order_bought($tom, $mug)]);
pin('another user has bought none of it', [false, false], [OrderLineStore::bought($sue, $tee), OrderLineStore::bought($sue, $hidden)]);
$sueTee = OrderStore::create($sue, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
OrderLineStore::add($sueTee->getId(), [['item_id' => $tee, 'variant_id' => $l, 'title' => 'Tee', 'unit' => $m(9), 'amount' => $m(9)]]);
Billing::markPaid($sueTee);
OrderStore::ship($sueTee->getId(), 'DHL', '1', 'https://t.example.test/1');
pin('shipped counts', true, OrderLineStore::bought($sue, $tee));
$refundedOnly = OrderStore::create($sue, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
OrderLineStore::add($refundedOnly->getId(), [['item_id' => $noPay, 'title' => 'No price', 'unit' => 1, 'amount' => 1]]);
Billing::markPaid($refundedOnly);
Billing::refund(OrderStore::find($refundedOnly->getId()));
pin('a refunded order does not', false, OrderLineStore::bought($sue, $noPay));
$got = OrderLineStore::boughtItems($tom, [$tee, $mug, $hidden, 0, $tee]);
sort($got);
$want = [$tee, $mug];
sort($want);
pin('boughtItems() answers several in one go', $want, $got);
$n = harness_query_count(static fn () => OrderLineStore::boughtItems($tom, [$tee, $mug]));
pin('in one query', $n < 0 ? $n : 1, $n);
$byOrder = OrderLineStore::ofOrders([$id, $second->getId(), 999999]);
pin('ofOrders() groups by order and leaves out an order with none', [3, 1, false], [count($byOrder[$id]), count($byOrder[$second->getId()]), isset($byOrder[999999])]);
$n = harness_query_count(static fn () => OrderLineStore::ofOrders([$id, $second->getId()]));
pin('ofOrders() reads once', $n < 0 ? $n : 1, $n);
$refusedAdd = false;
try {
    OrderLineStore::add($id, [['kind' => 'gift', 'title' => 'x']]);
} catch (InvalidArgumentException $e) {
    $refusedAdd = true;
}
pin('an unknown line kind is refused', true, $refusedAdd);

harness_section('order lists');
$credits = OrderStore::create($tom, 'fake', $m(5), 'USD', 100);
pin('a credits order keeps its label and has no fulfilment', ['100 credits', false, null], [$credits->getLabel(), $credits->isGoods(), $credits->fulfilment()]);
$goodsIds = array_map(static fn (Order $o): int => $o->getId(), OrderStore::search(['user_id' => $tom, 'goods' => true], 100));
pin('the goods filter leaves out credits orders', [false, true], [in_array($credits->getId(), $goodsIds, true), in_array($id, $goodsIds, true)]);
pin('and counts the same', count($goodsIds), OrderStore::searchCount(['user_id' => $tom, 'goods' => true]));
$all  = array_map(static fn (Order $o): int => $o->getId(), OrderStore::forUser($tom, 100));
$page = array_map(static fn (Order $o): int => $o->getId(), OrderStore::forUser($tom, 2, 0, $all[1]));
pin('forUser() is newest first and pages by id', array_slice($all, 2, 2), $page);
pin('the statuses include shipped and delivered', true, in_array(Order::STATUS_SHIPPED, Order::STATUSES, true) && in_array(Order::STATUS_DELIVERED, Order::STATUSES, true));

harness_section('what plugins see and add');
$saw = [];
$spy = static function ($lines, $cart) use (&$saw) {
    $saw = [$cart['address'], $cart['coupon']];

    return $lines;
};
osc_add_filter('cart_tax_lines', $spy);
$quote([['variant' => $mugV, 'qty' => 1]], ['address' => CartPricer::quoteAddress(' in ', '', '110017'), 'coupon' => ' welcome10 ']);
pin('the quote hands the filters the postcode, country and code, upper-cased', [['country' => 'IN', 'postcode' => '110017'], 'WELCOME10'], $saw);
$quote([['variant' => $mugV, 'qty' => 1]], ['address' => CartPricer::quoteAddress('IND', str_repeat('x', 101), str_repeat('1', 21)), 'coupon' => 'no spaces!']);
pin('a malformed country, state, postcode or code is left out', [[], null], $saw);
pin('a code longer than 40 is no code', [null, 'A-1'], [CartPricer::coupon(str_repeat('A', 41)), CartPricer::coupon('a-1')]);
osc_remove_filter('cart_tax_lines', $spy);

$problemsFilter = static function ($problems, $cart) {
    $problems[] = ['code' => 'not_serviceable', 'pointer' => '/address/postcode', 'message' => 'We do not deliver to this PIN code yet.'];
    $problems[] = ['code' => 'nope', 'pointer' => '/lines/0', 'message' => 'Unknown pointer'];
    $problems[] = ['code' => 'too_long', 'pointer' => '/coupon', 'message' => str_repeat('x', 300)];
    $problems[] = ['code' => 'Bad-Code', 'pointer' => '/coupon', 'message' => 'Bad code'];
    $problems[] = ['code' => 'empty', 'pointer' => '/coupon', 'message' => ' '];
    $problems[] = 'junk';

    return $problems;
};
osc_add_filter('cart_problems', $problemsFilter);
$q = $quote([['variant' => $mugV, 'qty' => 1]]);
pin('a cart_problems entry is kept with its pointer; an unknown pointer, a long or empty text and a bad code are dropped', [
    ['line' => null, 'code' => 'not_serviceable', 'pointer' => '/address/postcode', 'message' => 'We do not deliver to this PIN code yet.'],
], $q->problems());
$before = [$orders(), $lineRows()];
pin('placing refuses with it, as rejected', ['/address/postcode rejected'], $refused(static fn () => $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake')));
$message = '';
try {
    $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake');
} catch (InvalidException $e) {
    $message = $e->errors()[0]['message'];
}
pin('in the plugin\'s words, writing nothing', ['We do not deliver to this PIN code yet.', $before], [$message, [$orders(), $lineRows()]]);
osc_remove_filter('cart_problems', $problemsFilter);

$coupons = [];
$couponSpy = static function ($lines, $cart) use (&$coupons) {
    $coupons[] = $cart['coupon'];

    return $lines;
};
osc_add_filter('cart_discount_lines', $couponSpy);
$checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake', '', 'save5');
pin('place() hands the filters the code too', ['SAVE5'], $coupons);
osc_remove_filter('cart_discount_lines', $couponSpy);

$taxes = static function ($lines) use ($m) {
    $lines[] = ['id' => 'tax.vat.20', 'label' => 'VAT 20%', 'amount' => $m(1.5), 'meta' => ['included' => true, 'bp' => 2000]];
    $lines[] = ['id' => 'tax.levy', 'label' => 'Levy', 'amount' => $m(1)];
    $lines[] = ['id' => 'tax.str', 'label' => 'Not really included', 'amount' => $m(2), 'meta' => ['included' => 'true']];

    return $lines;
};
osc_add_filter('cart_tax_lines', $taxes);
$q = $quote([['variant' => $mugV, 'qty' => 1]]);
pin('an included tax line is marked; only a true flag counts', [true, false, false], array_column($q->extraLines('tax'), 'included'));
pin('it raises tax_included and leaves the total alone; an additive one still adds', [$m(3), $m(1.5), $m(9 + 49 + 3)], [$q->totals()['tax'], $q->totals()['tax_included'], $q->total()]);
$taxed = $checkout->place($tom, [['variant' => $mugV, 'qty' => 1]], $address, 'standard', 'fake')['order'];
$taxLines = array_values(array_filter(OrderLineStore::ofOrder($taxed->getId()), static fn ($x) => $x['kind'] === 'tax'));
pin('the included one is stored with amount 0 and the tax as its unit', [[0, $m(1.5), true], [$m(1), $m(1), null], [$m(2), $m(2), 'true']], array_map(static fn ($x) => [$x['amount'], $x['unit'], $x['meta']['included'] ?? null], $taxLines));
pin('so the lines still add up to the total', OrderStore::find($taxed->getId())->getAmount(), (int) array_sum(array_column(OrderLineStore::ofOrder($taxed->getId()), 'amount')));
$receipt = Receipts::details(OrderStore::find($taxed->getId()));
$vatRow  = array_values(array_filter($receipt['lines'], static fn ($x) => $x['title'] === 'VAT 20%'))[0];
check('the receipt and its mail show the tax as included', $vatRow['included'] === true && str_contains(Receipts::linesHtml($receipt), '>' . osc_esc_html(\mindstellar\currency\CurrencyService::make()->money($m(1.5), 'USD')) . ' (included)<'), Receipts::linesHtml($receipt));
osc_remove_filter('cart_tax_lines', $taxes);
OrderStore::cancel($taxed->getId());

$free = null;
$freeOver = static function ($core, $cart) use (&$free) {
    return $free;
};
osc_add_filter('cart_free_over', $freeOver);
$free = ['amount' => $m(500), 'remaining' => 1];
pin('cart_free_over replaces the meter; remaining is core\'s sum', ['amount' => $m(500), 'remaining' => $m(491)], $quote([['variant' => $mugV, 'qty' => 1]])->freeOver());
$free = null;
pin('null takes the meter away', null, $quote([['variant' => $mugV, 'qty' => 1]])->freeOver());
$free = ['amount' => 'lots'];
pin('a malformed answer keeps core\'s', ['amount' => $m(999), 'remaining' => $m(990)], $quote([['variant' => $mugV, 'qty' => 1]])->freeOver());
osc_remove_filter('cart_free_over', $freeOver);

harness_section('tracking links and receipt details from plugins');
$track = static fn ($url, $carrier, $tracking) => $carrier === 'Delhivery' ? 'https://track.example.test/' . $tracking : 'http://insecure.example.test/' . $tracking;
osc_add_filter('billing_tracking_url', $track, 10, 3);
$shipA = OrderStore::create($tom, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
$shipB = OrderStore::create($tom, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
$shipC = OrderStore::create($tom, 'fake', $m(9), 'USD', 0, ['_kind' => 'goods']);
foreach ([$shipA, $shipB, $shipC] as $o) {
    Billing::markPaid($o);
}
OrderStore::ship($shipA->getId(), ' Delhivery ', ' AB1 ', '');
OrderStore::ship($shipB->getId(), 'Other', 'AB2', '');
OrderStore::ship($shipC->getId(), 'Delhivery', 'AB3', 'https://own.example.test/AB3');
pin('with no URL the filter\'s https one is kept, an http one refused, a given one wins', ['https://track.example.test/AB1', '', 'https://own.example.test/AB3'], [
    OrderStore::find($shipA->getId())->fulfilment()['tracking_url'], OrderStore::find($shipB->getId())->fulfilment()['tracking_url'], OrderStore::find($shipC->getId())->fulfilment()['tracking_url'],
]);
osc_remove_filter('billing_tracking_url', $track);

$invoice = static function ($details, $order) {
    $details['title']     = 'Tax <invoice>';
    $details['extra'][]   = ['label' => 'GSTIN', 'value' => '07AAA<b>1Z5'];
    $details['extra'][]   = ['label' => '', 'value' => 'no label'];
    $details['summary'][] = ['label' => 'Taxable value', 'value' => '$ 9.00'];
    $details['footer']    = 'Thank you. <script>x</script>';
    $details['total']     = '$ 0.00';
    $details['lines']     = [];

    return $details;
};
osc_add_filter('billing_receipt_details', $invoice, 10, 2);
$shown = Receipts::details(OrderStore::find($shipA->getId()));
pin('the filter sets title, extra, summary and footer, and nothing else', ['Tax <invoice>', [['label' => 'GSTIN', 'value' => '07AAA<b>1Z5']], [['label' => 'Taxable value', 'value' => '$ 9.00']], 'Thank you. <script>x</script>', true], [
    $shown['title'], $shown['extra'], $shown['summary'], $shown['footer'], $shown['total'] !== '$ 0.00',
]);
ob_start();
Receipts::render(OrderStore::find($shipA->getId()), 'https://example.test/', 'Back');
$html = (string) ob_get_clean();
check('the receipt page prints them escaped', str_contains($html, 'Tax &lt;invoice&gt; ') && str_contains($html, '07AAA&lt;b&gt;1Z5') && str_contains($html, 'Taxable value')
    && str_contains($html, 'Thank you. &lt;script&gt;') && !str_contains($html, '<script>') && !str_contains($html, 'not a tax invoice'));
osc_remove_filter('billing_receipt_details', $invoice);
ob_start();
Receipts::render(OrderStore::find($shipA->getId()), 'https://example.test/', 'Back');
$html = (string) ob_get_clean();
check('without it the receipt reads as before', str_contains($html, 'Receipt #') && str_contains($html, 'not a tax invoice'));

harness_section('counting a user\'s orders');
$ann   = seed_user($admin, 'ann', 'ann@example.test');
$annId = static fn (string $gateway, string $status = Order::STATUS_PENDING, int $credits = 0): int => (static function () use ($ann, $gateway, $status, $credits, $m, $admin, $p): int {
    $o = OrderStore::create($ann, $gateway, $m(9), 'USD', $credits, $credits > 0 ? [] : ['_kind' => 'goods']);
    $admin->query("UPDATE {$p}t_billing_order SET s_status = '" . $status . "' WHERE pk_i_id = " . $o->getId());

    return $o->getId();
})();
$annId('cod');
$annId('cod');
$annId('offline');
$annId('fake');
$annId('cod', Order::STATUS_CANCELLED);
$annId('fake', Order::STATUS_PAID);
$annId('fake', Order::STATUS_PENDING, 100);
OrderStore::create($sue, 'cod', $m(9), 'USD', 0, ['_kind' => 'goods']);
pin('pending goods orders of one user, credits orders and other users left out', 4, OrderStore::countForUser($ann, [Order::STATUS_PENDING]));
pin('through some gateways only', [3, 2], [OrderStore::countForUser($ann, [Order::STATUS_PENDING], Billing::HOLDS_STOCK), OrderStore::countForUser($ann, [Order::STATUS_PENDING], ['cod'])]);
pin('several statuses', 6, OrderStore::countForUser($ann, [Order::STATUS_PENDING, Order::STATUS_CANCELLED, Order::STATUS_PAID]));
pin('no status, no gateway or no user counts nothing', [0, 0, 0], [OrderStore::countForUser($ann, []), OrderStore::countForUser($ann, [Order::STATUS_PENDING], []), OrderStore::countForUser(0, [Order::STATUS_PENDING])]);
pin('osc_billing_user_order_count reads the same', 5, osc_billing_user_order_count($ann, [Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED]));
$n = harness_query_count(static fn () => OrderStore::countForUser($ann, [Order::STATUS_PENDING], ['cod']));
pin('in one query', $n < 0 ? $n : 1, $n);

harness_section('the personal data export');
$export = \mindstellar\privacy\PersonalData::export($tom);
$mine   = array_column($export['data']['t_billing_order']['lines'] ?? [], 'order_id');
pin('carries the lines of their orders', true, in_array($id, $mine, true));
pin('and only theirs', false, in_array($pending->getId(), $mine, true));
pin('and does not list the lines table as left out', false, isset($export['not_included']['t_billing_order_line']));

harness_section('migration 0080');
$ddl   = static fn (): array => array_map('array_values', $admin->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_billing_order_line' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC));
$idx   = static fn (): array => array_map('array_values', $admin->query("SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_billing_order_line' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetch_all(MYSQLI_ASSOC));
$fks   = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_billing_order_line' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetch_row()[0];
pin('the table has no foreign key', 0, $fks());
$fresh = [$ddl(), $idx()];
$admin->query("DROP TABLE {$p}t_billing_order_line");
$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0080_billing_order_line.php';
$migration->up(\mindstellar\database\Connection::getInstance());
pin('the migration builds the table struct.sql builds', $fresh, [$ddl(), $idx()]);
$admin->query("INSERT INTO {$p}t_billing_order_line (fk_i_order_id, s_title) VALUES (1, 'kept')");
$migration->up(\mindstellar\database\Connection::getInstance());
pin('and runs again keeping the rows', [$fresh, 1], [[$ddl(), $idx()], (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order_line")->fetch_row()[0]]);

exit(harness_result());
