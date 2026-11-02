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
 * Pins the cart events: the line rules of addLine()/addFee()/addMethod()/add(), the LineTax shape
 * and setLineTax(), and that an old Plugins::addHook() callback on each cart name folds into its
 * event and a typed one gets the event.
 *
 * DB-free.  Usage: php tests/cart-events.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Plugins.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\billing\event\CartDiscountLines;
use mindstellar\billing\event\CartFees;
use mindstellar\billing\event\CartFreeOver;
use mindstellar\billing\event\CartItemAdded;
use mindstellar\billing\event\CartLineEvent;
use mindstellar\billing\event\CartProblems;
use mindstellar\billing\event\CartShipping;
use mindstellar\billing\event\CartShippingLines;
use mindstellar\billing\event\CartTaxLines;
use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\event\Line;
use mindstellar\billing\LineTax;
use mindstellar\container\Container;
use mindstellar\event\Dispatcher;

if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}

$cart = ['currency' => 'USD', 'subtotal' => 40000000, 'user_id' => 3, 'gateway' => 'cod', 'coupon' => 'SAVE', 'lines' => [['variant' => 1]]];

$refuses = static function (callable $fn): bool {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }

    return false;
};

harness_section('The cart view');

$view = (new CartTaxLines($cart))->cart();
pin('it reads the cart', ['USD', 40000000, 3, 'cod', 'SAVE', null, true], [$view->currency(), $view->subtotal(), $view->userId(), $view->gateway(), $view->coupon(), $view->shippingMethod(), $view->needsShipping()]);

harness_section('Line rules');

$tax = new CartTaxLines($cart);
$tax->addLine(new Line('tax.vat', 'VAT', 5000000, 'USD', ['bp' => 1000]));
pin('a tax line is kept', [['id' => 'tax.vat', 'label' => 'VAT', 'amount' => 5000000, 'currency' => 'USD', 'meta' => ['bp' => 1000]]], $tax->rawLines());
check('a tax line below zero is refused', $refuses(static fn () => $tax->addLine(new Line('tax.x', 'X', -1, 'USD'))));
check('a line in another currency is refused', $refuses(static fn () => $tax->addLine(new Line('tax.y', 'Y', 1, 'EUR'))));
check('a line with no label is refused', $refuses(static fn () => $tax->addLine(new Line('tax.z', ' ', 1, 'USD'))));
check('a bad id is refused', $refuses(static fn () => $tax->addLine(new Line('Bad Id', 'Z', 1, 'USD'))));
pin('lines() returns Line objects', ['tax.vat'], array_map(static fn (Line $l): string => $l->id(), $tax->lines()));
$tax->removeLine('tax.vat');
pin('removeLine() takes it out', [], $tax->rawLines());

$tax->addLine(new Line('tax.long', ' ' . str_repeat('L', 250), 1, 'USD'));
pin('a long label is trimmed and cut where core cuts it', Line::MAX_LABEL, mb_strlen($tax->rawLines()[0]['label']));
for ($n = 1; $n < Line::MAX_PER_EVENT; $n++) {
    $tax->addLine(new Line('tax.n' . $n, 'N', 1, 'USD'));
}
check('a line past the most one event holds is refused', $refuses(static fn () => $tax->addLine(new Line('tax.over', 'Over', 1, 'USD'))));

$discount = new CartDiscountLines($cart);
$discount->addLine(new Line('discount.c', 'Coupon', 3000000, 'USD'));
pin('a discount always takes off', -3000000, $discount->rawLines()[0]['amount']);

$fees = new CartFees($cart, ['subtotal' => 40000000, 'total' => 40000000]);
$fees->addFee(new Line('fee.cod', 'Fee', 2000000, 'USD'));
pin('a fee is kept', [['id' => 'fee.cod', 'label' => 'Fee', 'amount' => 2000000, 'currency' => 'USD', 'meta' => []]], $fees->rawFees());
pin('totals() leaves the fees out', ['subtotal' => 40000000, 'total' => 40000000], $fees->totals());
check('a negative fee is refused', $refuses(static fn () => $fees->addFee(new Line('fee.n', 'N', -1, 'USD'))));

$ship = new CartShipping($cart);
$ship->addMethod('express', 'Express', 9000000, '2 days');
pin('a method is kept', [['id' => 'express', 'label' => 'Express', 'eta' => '2 days', 'amount' => 9000000]], $ship->methods());
check('a method with a bad id is refused', $refuses(static fn () => $ship->addMethod('No Good', 'X', 1)));
check('a method with an empty label is refused', $refuses(static fn () => $ship->addMethod('x', '  ', 1)));
check('a method below zero is refused', $refuses(static fn () => $ship->addMethod('free', 'Free', -1)));

$problems = new CartProblems($cart);
$problems->add('region', '/address/state', 'We do not deliver there.');
check('a problem is kept', count($problems->rawProblems()) === 1);
check('a problem at an unknown pointer is refused', $refuses(static fn () => $problems->add('region', '/nope', 'x')));
check('a problem with a bad code is refused', $refuses(static fn () => $problems->add('Bad-Code', '/coupon', 'x')));

$free = new CartFreeOver($cart, null);
$free->setFreeOver(100000000);
pin('the meter keeps the subtotal rule', ['amount' => 100000000, 'remaining' => 60000000], $free->freeOver());

$started = new CheckoutStarting($cart);
$started->refuse('/coupon', 'expired', 'Gone.');
pin('refuse() records and stops the run', [[true], [['pointer' => '/coupon', 'code' => 'expired', 'message' => 'Gone.']]], [[$started->isPropagationStopped()], $started->refusals()]);

harness_section('Line tax');

$part = static fn (string $tax, int $amount = 1, int $bp = 1000, string $label = 'VAT'): array => ['tax' => $tax, 'label' => $label, 'bp' => $bp, 'amount' => $amount];
$vat  = new LineTax(false, 100, [$part('tax.cgst.9', 4), $part('tax.sgst.9', 5)], ' 6109 ');
pin('amount is the sum of the parts; the code is trimmed', [9, '6109'], [$vat->amount, $vat->code]);
pin('a long part label is cut', LineTax::MAX_LABEL, mb_strlen((new LineTax(true, 0, [$part('tax.a', 0, 0, str_repeat('L', 80))]))->parts[0]['label']));
pin('an empty code is none', null, (new LineTax(false, 1, [$part('tax.a')], ''))->code);
check('no parts are refused', $refuses(static fn () => new LineTax(false, 1, [])));
check('five parts are refused', $refuses(static fn () => new LineTax(false, 1, array_map(static fn (int $n): array => $part('tax.p' . $n), range(1, 5)))));
check('a part below zero is refused', $refuses(static fn () => new LineTax(false, 1, [$part('tax.a', -1)])));
check('a rate above 10000 bp is refused', $refuses(static fn () => new LineTax(false, 1, [$part('tax.a', 1, 10001)])));
check('a float amount is refused', $refuses(static fn () => new LineTax(false, 1, [['tax' => 'tax.a', 'label' => 'A', 'bp' => 1, 'amount' => 1.0]])));
check('a part with no label is refused', $refuses(static fn () => new LineTax(false, 1, [$part('tax.a', 1, 1, ' ')])));
check('a bad tax line id is refused', $refuses(static fn () => new LineTax(false, 1, [$part('Tax A')])));
check('the same tax line twice is refused', $refuses(static fn () => new LineTax(false, 1, [$part('tax.a'), $part('tax.a')])));
check('a base below zero is refused', $refuses(static fn () => new LineTax(false, -1, [$part('tax.a')])));
check('a code over 40 characters is refused', $refuses(static fn () => new LineTax(false, 1, [$part('tax.a')], str_repeat('9', 41))));

$taxCart = $cart + ['shipping_lines' => [['id' => 'shipping.standard', 'label' => 'Standard', 'amount' => 1, 'currency' => 'USD', 'meta' => []]]];
$taxCart['lines'] = [['line' => 0, 'variant' => 1], ['line' => 2, 'variant' => 5]];
$lt = new CartTaxLines($taxCart);
$lt->setLineTax('item', 2, $vat);
$lt->setLineTax('shipping', 'shipping.standard', $vat);
pin('setLineTax() keeps it by cart position and shipping id', [[2], ['shipping.standard']], [array_keys($lt->lineTax()['item']), array_keys($lt->lineTax()['shipping'])]);
check('an item position the cart does not hold is refused', $refuses(static fn () => $lt->setLineTax('item', 1, $vat)));
check('an item named by a string is refused', $refuses(static fn () => $lt->setLineTax('item', '2', $vat)));
check('an unknown shipping line is refused', $refuses(static fn () => $lt->setLineTax('shipping', 'shipping.express', $vat)));
check('another kind is refused', $refuses(static fn () => $lt->setLineTax('fee', 0, $vat)));
pin('a new cart event starts with none', ['item' => [], 'shipping' => []], (new CartTaxLines($cart))->lineTax());

harness_section('Old callbacks fold into the events');

harness_reset_container();
$events = Container::root()->get(Dispatcher::class);

Plugins::addHook('cart_needs_shipping', static fn ($needs, $c) => $c['user_id'] === 3 ? false : $needs);
pin('cart_needs_shipping', false, $events->dispatch(new CartShipping($cart))->needsShipping());
pin('cart_shipping_methods is not asked when nothing ships', [], $events->dispatch(new CartShipping($cart))->methods());
Plugins::removeHook('cart_needs_shipping', Plugins::callbacks('cart_needs_shipping')[0]);

$sawNeeds = null;
Plugins::addHook('cart_shipping_methods', static function ($methods, $c) use (&$sawNeeds) {
    $sawNeeds = $c['needs_shipping'];
    $methods[] = ['id' => 'old', 'label' => 'Old', 'eta' => null, 'amount' => 1];

    return $methods;
}, 5);
Plugins::addHook('cart_shipping_methods', static function (CartShipping $e): void {
    $e->addMethod('typed', 'Typed', 2);
}, 6);
$ship = $events->dispatch(new CartShipping($cart));
pin('cart_shipping_methods: old and typed in priority order', ['old', 'typed'], array_column($ship->methods(), 'id'));
pin('the old callback sees needs_shipping in the cart', true, $sawNeeds);

foreach ([['cart_shipping_lines', CartShippingLines::class, 'shipping'], ['cart_tax_lines', CartTaxLines::class, 'tax'], ['cart_discount_lines', CartDiscountLines::class, 'discount']] as [$name, $class, $kind]) {
    Plugins::addHook($name, static function ($lines, $c) use ($kind) {
        $lines[] = ['id' => $kind . '.old', 'label' => 'Old', 'amount' => 1, 'currency' => $c['currency'], 'meta' => []];

        return $lines;
    }, 3);
    Plugins::addHook($name, static function (CartLineEvent $e) use ($kind): void {
        $e->addLine(new Line($kind . '.typed', 'Typed', 2, 'USD'));
    }, 4);
    $event = $events->dispatch(new $class($cart, [['id' => 'first', 'label' => 'First', 'amount' => 1, 'currency' => 'USD', 'meta' => []]]));
    pin($name . ' sees the lines so far and adds one', ['first', $kind . '.old', $kind . '.typed'], array_column($event->rawLines(), 'id'));
}

Plugins::addHook('cart_tax_lines', static function (CartTaxLines $e) use ($vat): void {
    $e->setLineTax('item', 0, $vat);
}, 2);
$event = $events->dispatch(new CartTaxLines($taxCart));
pin('line tax set by a typed listener stays through an old array callback', [[0], ['tax.old', 'tax.typed']], [array_keys($event->lineTax()['item']), array_column($event->rawLines(), 'id')]);

Plugins::addHook('cart_totals', static function ($totals, $c) {
    $totals['fees'][] = ['id' => 'fee.old', 'label' => 'Old', 'amount' => 1];

    return $totals;
});
Plugins::addHook('cart_totals', static function (CartFees $e): void {
    $e->addFee(new Line('fee.typed', 'Typed', 2, 'USD'));
}, 6);
pin('cart_totals', ['fee.old', 'fee.typed'], array_column($events->dispatch(new CartFees($cart, ['total' => 1]))->rawFees(), 'id'));

Plugins::addHook('cart_free_over', static fn ($free, $c) => ['amount' => 5, 'remaining' => 0]);
pin('cart_free_over', ['amount' => 5, 'remaining' => 0], $events->dispatch(new CartFreeOver($cart, null))->freeOver());

Plugins::addHook('cart_problems', static function ($problems, $c) {
    $problems[] = ['code' => 'old', 'pointer' => '/coupon', 'message' => 'm'];

    return $problems;
});
Plugins::addHook('cart_problems', static function (CartProblems $e): void {
    $e->add('typed', '/coupon', 'm');
}, 6);
pin('cart_problems', ['old', 'typed'], array_column($events->dispatch(new CartProblems($cart))->rawProblems(), 'code'));

$seen = [];
Plugins::addHook('cart_item_add_after', static function ($c, $line) use (&$seen): void {
    $seen[] = [$c['user_id'], $line['id'], $line['meta']['qty']];
});
Plugins::addHook('cart_item_add_after', static function (CartItemAdded $e) use (&$seen): void {
    $seen[] = [$e->variantId(), $e->qty(), $e->itemId()];
}, 6);
$events->dispatch(new CartItemAdded($cart, new Line('item.7', 'Mug', 100, 'USD', ['variant' => 7, 'qty' => 2, 'item_id' => 9])));
pin('cart_item_add_after', [[3, 'item.7', 2], [7, 2, 9]], $seen);

Plugins::addHook('cart_checkout_before', static function ($c): void {
    throw new RuntimeException('old callback refused');
});
$thrown = '';
try {
    $events->dispatch(new CheckoutStarting($cart));
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
pin('cart_checkout_before: an old callback still refuses by throwing', 'old callback refused', $thrown);

exit(harness_result());
