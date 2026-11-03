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
 * Line tax: a CartTaxLines listener breaks the tax lines down per item and shipping line, the
 * pricer keeps that breakdown only when its parts add up to every tax line (with the same
 * included flag), and GoodsCheckout writes it to the order lines' meta `tax`, where
 * OrderLineStore::lineTax() reads it back. A mismatch drops all line tax and still places.
 * Usage: php tests/billing-line-tax.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\CartPricer;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\event\CartTaxLines;
use mindstellar\billing\event\Line;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\LineTax;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_billing_line_tax');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$m   = static fn (float $units): int => (int) round($units * 1000000);
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_shipping_flat' => (string) $m(5)] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

final class LineTaxFakeGateway implements PaymentGateway
{
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
        return CheckoutIntent::redirect('https://pay.example.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored('none');
    }
}
PaymentGatewayRegistry::getInstance()->register(new LineTaxFakeGateway());

$tee  = VariantStore::saveDefault(seed_item($admin, $cat, $sue, 'Tee', 100.0), ['i_price' => $m(100), 'i_stock' => null]);
$mug  = VariantStore::saveDefault(seed_item($admin, $cat, $sue, 'Mug', 9.0), ['i_price' => $m(9), 'i_stock' => null]);
$cart = [['variant' => $tee, 'qty' => 2], ['variant' => $mug, 'qty' => 1]];
$address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];

$pricer   = new CartPricer();
$checkout = new GoodsCheckout($pricer);

// What the listener adds: tax lines as [id, label, amount, included] and line tax as [kind, ref, LineTax].
$plan     = ['lines' => [], 'line_tax' => []];
$listener = static function (CartTaxLines $e) use (&$plan): void {
    foreach ($plan['lines'] as [$id, $label, $amount, $included]) {
        $e->addLine(new Line($id, $label, $amount, 'USD', $included ? ['included' => true] : []));
    }
    foreach ($plan['line_tax'] as [$kind, $ref, $tax]) {
        $e->setLineTax($kind, $ref, $tax);
    }
};
osc_add_filter('cart_tax_lines', $listener);

$warnings = [];
$quote    = static function (array $ctx = []) use ($pricer, $cart, &$warnings) {
    set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;

        return true;
    }, E_USER_WARNING);
    try {
        return $pricer->quote($cart, $ctx);
    } finally {
        restore_error_handler();
    }
};
$part  = static fn (string $tax, string $label, int $bp, int $amount): array => ['tax' => $tax, 'label' => $label, 'bp' => $bp, 'amount' => $amount];
$parts = static fn (?LineTax $t): ?array => $t?->toArray();

harness_section('tax on top');

$plan = [
    'lines'    => [['tax.vat.10', 'VAT 10%', $m(20.9), false]],
    'line_tax' => [
        ['item', 0, new LineTax(false, $m(200), [$part('tax.vat.10', 'VAT', 1000, $m(20))], 'HSN 6109')],
        ['item', 1, new LineTax(false, $m(9), [$part('tax.vat.10', 'VAT', 1000, $m(0.9))])],
    ],
];
$warnings = [];
$q = $quote();
pin('each item line has its own tax', [$m(20), $m(0.9)], [$q->lineTax('item', 0)?->amount, $q->lineTax('item', 1)?->amount]);
pin('the code is kept', 'HSN 6109', $q->lineTax('item', 0)?->code);
pin('the tax line stays the source of the total', [$m(20.9), $m(200 + 9 + 5 + 20.9)], [$q->totals()['tax'], $q->total()]);
pin('no shipping line tax was set', null, $q->lineTax('shipping', 'shipping.standard'));
pin('no warning', [], $warnings);

harness_section('tax in the prices');

$plan = [
    'lines'    => [['tax.vat.10', 'VAT 10%', $m(19), true]],
    'line_tax' => [
        ['item', 0, new LineTax(true, $m(181.818182), [$part('tax.vat.10', 'VAT', 1000, $m(18.181818))])],
        ['item', 1, new LineTax(true, $m(8.181818), [$part('tax.vat.10', 'VAT', 1000, $m(0.818182))])],
    ],
];
$q = $quote();
pin('included line tax is kept and the total leaves it alone', [true, $m(18.181818), $m(19), $m(214)], [$q->lineTax('item', 0)?->included, $q->lineTax('item', 0)?->amount, $q->totals()['tax_included'], $q->total()]);

harness_section('two parts and taxed shipping');

$gst = static fn (int $base, int $each): LineTax => new LineTax(false, $base, [$part('tax.cgst.9', 'CGST', 900, $each), $part('tax.sgst.9', 'SGST', 900, $each)], '6109');
$plan = [
    'lines'    => [['tax.cgst.9', 'CGST 9%', $m(18 + 0.81 + 0.45), false], ['tax.sgst.9', 'SGST 9%', $m(18 + 0.81 + 0.45), false]],
    'line_tax' => [
        ['item', 0, $gst($m(200), $m(18))],
        ['item', 1, $gst($m(9), $m(0.81))],
        ['shipping', 'shipping.standard', $gst($m(5), $m(0.45))],
    ],
];
$warnings = [];
$q = $quote();
pin('the parts are kept in order', [['tax.cgst.9', $m(18)], ['tax.sgst.9', $m(18)]], array_map(static fn (array $x): array => [$x['tax'], $x['amount']], $q->lineTax('item', 0)?->parts ?? []));
pin('the shipping line has its tax', [$m(5), $m(0.9)], [$q->lineTax('shipping', 'shipping.standard')?->base, $q->lineTax('shipping', 'shipping.standard')?->amount]);
pin('no warning', [], $warnings);

$placed = $checkout->place($tom, $cart, $address, 'standard', 'fake');
$lines  = OrderLineStore::ofOrder($placed['order']->getId());
$byKind = static fn (string $kind): array => array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === $kind));
pin('written meta reads back equal: items', [$parts($q->lineTax('item', 0)), $parts($q->lineTax('item', 1))], array_map(static fn (array $l): ?array => $parts(OrderLineStore::lineTax($l)), $byKind('item')));
pin('written meta reads back equal: shipping', $parts($q->lineTax('shipping', 'shipping.standard')), $parts(OrderLineStore::lineTax($byKind('shipping')[0])));
pin('the shipping line keeps its other meta', ['shipping.standard', 'standard'], [$byKind('shipping')[0]['meta']['id'], $byKind('shipping')[0]['meta']['method']]);
pin('tax lines carry no line tax', [null, null], array_map(static fn (array $l): ?LineTax => OrderLineStore::lineTax($l), $byKind('tax')));
pin('the order total is the sum of its lines', OrderStore::find($placed['order']->getId())->getAmount(), (int) array_sum(array_column($lines, 'amount')));
OrderStore::cancel($placed['order']->getId());

harness_section('after a discount');

$discount = static function ($lines) use ($m) {
    $lines[] = ['id' => 'discount.c', 'label' => 'Coupon', 'amount' => -$m(20), 'meta' => []];

    return $lines;
};
osc_add_filter('cart_discount_lines', $discount);
$plan = [
    'lines'    => [['tax.vat.10', 'VAT 10%', $m(18.9), false]],
    'line_tax' => [
        ['item', 0, new LineTax(false, $m(180), [$part('tax.vat.10', 'VAT', 1000, $m(18))])],
        ['item', 1, new LineTax(false, $m(9), [$part('tax.vat.10', 'VAT', 1000, $m(0.9))])],
    ],
];
$q = $quote();
pin('line tax on the discounted base is kept', [$m(180), $m(18), $m(200 + 9 + 5 - 20 + 18.9)], [$q->lineTax('item', 0)?->base, $q->lineTax('item', 0)?->amount, $q->total()]);
osc_remove_filter('cart_discount_lines', $discount);

harness_section('a breakdown that does not add up is dropped');

$plan = [
    'lines'    => [['tax.vat.10', 'VAT 10%', $m(20.9), false]],
    'line_tax' => [
        ['item', 0, new LineTax(false, $m(200), [$part('tax.vat.10', 'VAT', 1000, $m(20))])],
        ['item', 1, new LineTax(false, $m(9), [$part('tax.vat.10', 'VAT', 1000, $m(0.9) - 1)])],
    ],
];
$warnings = [];
$q = $quote();
pin('one micro off drops all line tax', [null, null], [$q->lineTax('item', 0), $q->lineTax('item', 1)]);
pin('with one warning', 1, count($warnings));
pin('the tax line still counts', [$m(20.9), $m(234.9)], [$q->totals()['tax'], $q->total()]);
$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
}, E_USER_WARNING);
$placed = $checkout->place($tom, $cart, $address, 'standard', 'fake');
restore_error_handler();
$lines = OrderLineStore::ofOrder($placed['order']->getId());
pin('the order still places with its tax line', [Order::STATUS_PENDING, $m(234.9), [$m(20.9)]], [
    OrderStore::find($placed['order']->getId())->getStatus(),
    OrderStore::find($placed['order']->getId())->getAmount(),
    array_column(array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'tax')), 'amount'),
]);
pin('and no line carries tax meta', [false, false, false, false], array_map(static fn (array $l): bool => isset($l['meta']['tax']), $lines));
OrderStore::cancel($placed['order']->getId());

$plan['line_tax'][1][2] = new LineTax(true, $m(9), [$part('tax.vat.10', 'VAT', 1000, $m(0.9))]);
$warnings = [];
pin('an included flag that differs drops it', [null, 1], [$quote()->lineTax('item', 0), count($warnings)]);

// The vat.10 parts add up exactly, so only the unknown id can drop it.
$plan['line_tax'][1][2] = new LineTax(false, $m(9), [$part('tax.vat.10', 'VAT', 1000, $m(0.9)), $part('tax.vat.20', 'VAT', 2000, 0)]);
$warnings = [];
pin('a part naming no tax line drops it', [null, 1], [$quote()->lineTax('item', 0), count($warnings)]);
check('and the warning names it', str_contains($warnings[0] ?? '', '"tax.vat.20", which is not a tax line'));

$plan['line_tax'] = [$plan['line_tax'][0]];
$warnings = [];
pin('a line left out drops it', [null, 1], [$quote()->lineTax('item', 0), count($warnings)]);

harness_section('old array filters');

$plan  = ['lines' => [], 'line_tax' => []];
$levy  = static function ($lines) use ($m) {
    $lines[] = ['id' => 'tax.levy', 'label' => 'Levy', 'amount' => $m(1)];

    return $lines;
};
osc_add_filter('cart_tax_lines', $levy);
$warnings = [];
$q = $quote();
pin('an old filter still adds its tax line, with no line tax and no warning', [['tax.levy'], null, []], [array_column($q->extraLines('tax'), 'id'), $q->lineTax('item', 0), $warnings]);
osc_remove_filter('cart_tax_lines', $levy);

$sneaky = static function ($lines) use ($m) {
    $lines[] = ['id' => 'tax.x', 'label' => 'X', 'amount' => $m(1), 'meta' => ['tax' => ['included' => false, 'base' => 1, 'parts' => []]]];

    return $lines;
};
$sneakyShip = static function ($lines) {
    $lines[0]['meta']['tax'] = (new LineTax(false, 1, [['tax' => 'tax.x', 'label' => 'X', 'bp' => 1, 'amount' => 1]]))->toArray();

    return $lines;
};
osc_add_filter('cart_tax_lines', $sneaky);
osc_add_filter('cart_shipping_lines', $sneakyShip);
$placed = $checkout->place($tom, $cart, $address, 'standard', 'fake');
$lines  = OrderLineStore::ofOrder($placed['order']->getId());
pin('a `tax` key a filter puts in line meta is not stored', [false, false, false, false], array_map(static fn (array $l): bool => isset($l['meta']['tax']), $lines));
osc_remove_filter('cart_tax_lines', $sneaky);
osc_remove_filter('cart_shipping_lines', $sneakyShip);
OrderStore::cancel($placed['order']->getId());

harness_section('reading stored line tax');

$stored = (new LineTax(false, 100, [$part('tax.a', 'A', 100, 1)]))->toArray();
pin('a whole one reads back', $stored, OrderLineStore::lineTax(['kind' => 'item', 'meta' => ['tax' => $stored]])?->toArray());
pin('an amount that is not the sum of the parts does not', null, OrderLineStore::lineTax(['kind' => 'item', 'meta' => ['tax' => ['amount' => 2] + $stored]]));
pin('a broken one does not', null, OrderLineStore::lineTax(['kind' => 'item', 'meta' => ['tax' => ['parts' => []] + $stored]]));
pin('an old line without it has none', null, OrderLineStore::lineTax(['kind' => 'item', 'meta' => []]));
pin('a tax line has none', null, OrderLineStore::lineTax(['kind' => 'tax', 'meta' => ['tax' => $stored]]));

osc_remove_filter('cart_tax_lines', $listener);

exit(harness_result());
