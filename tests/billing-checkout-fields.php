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
 * Checkout fields: a CheckoutFields listener asks for extra text fields, the quote lists them, and
 * GoodsCheckout::place() checks the answers (required, length), lets CheckoutStarting refuse or
 * normalise them and keeps the non-empty ones in the order's meta `_fields`.
 * Usage: php tests/billing-checkout-fields.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\CartPricer;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\event\CheckoutFields;
use mindstellar\billing\event\CheckoutStarting;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\exception\InvalidException;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_billing_checkout_fields');
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

final class FieldsFakeGateway implements PaymentGateway
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
PaymentGatewayRegistry::getInstance()->register(new FieldsFakeGateway());

$tee  = VariantStore::saveDefault(seed_item($admin, $cat, $sue, 'Tee', 100.0), ['i_price' => $m(100), 'i_stock' => null]);
$cart = [['variant' => $tee, 'qty' => 1]];
$address = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];

$pricer   = new CartPricer();
$checkout = new GoodsCheckout($pricer);
$place    = static fn (array $fields) => $checkout->place($tom, $cart, $address, 'standard', 'fake', '', null, $fields);
$refused  = static function (callable $fn): array {
    try {
        $fn();
    } catch (InvalidException $e) {
        return array_map(static fn (array $x): string => $x['pointer'] . ' ' . $x['code'], $e->errors());
    }

    return ['not refused'];
};
$bad = static function (callable $fn): bool {
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return true;
    }

    return false;
};

harness_section('the event');

$ev = new CheckoutFields(['currency' => 'USD']);
$ev->addField('tax_id', 'Tax number', true, 12, 'Help', true, 'off');
$ev->addField('tax_id', 'Tax ID', true);
pin('a field added twice keeps the later one', [['tax_id', 'Tax ID', 64]], array_map(static fn ($f) => [$f['id'], $f['label'], $f['max']], $ev->fields()));
check('an id with capitals is refused', $bad(static fn () => $ev->addField('Tax', 'x')));
check('an empty label is refused', $bad(static fn () => $ev->addField('a', ' ')));
check('a max over 100 is refused', $bad(static fn () => $ev->addField('a', 'x', false, 101)));
$many = new CheckoutFields([]);
foreach (range(1, 9) as $n) {
    $many->addField('f' . $n, 'Field ' . $n);
}
pin('the 9th field is ignored', [8, 'f8'], [count($many->fields()), $many->fields()[7]['id']]);
$old = new CheckoutFields([]);
$old->applyLegacy('checkout_fields', static fn ($f, $c) => array_merge($f, [['id' => 'vat', 'label' => 'VAT number'], ['id' => 'BAD ID', 'label' => 'x']]));
pin('an old filter adds a field; a bad one is dropped', ['vat'], array_column($old->fields(), 'id'));

harness_section('the quote');

pin('no listener, no fields', [], $pricer->quote($cart)->checkoutFields());
$asks = static function (CheckoutFields $e): void {
    $e->addField('tax_id', 'Tax number', true, 12, 'On the invoice.', true, 'off');
    $e->addField('gift', 'Gift note', false, 30, '', false);
};
osc_add_filter('checkout_fields', $asks);
$q = $pricer->quote($cart, ['fields' => ['tax_id' => ' de123 ', 'gift' => 'Hi', 'nope' => 'x', 'BAD' => 'y']]);
pin('the quote lists the fields', ['tax_id', 'gift'], array_column($q->checkoutFields(), 'id'));
pin('the cart holds the values of asked fields only, trimmed', ['tax_id' => 'de123', 'gift' => 'Hi'], $q->cart()['fields']);
pin('a missing answer is an empty value', ['tax_id' => '', 'gift' => ''], $pricer->quote($cart)->cart()['fields']);

harness_section('placing');

pin('a missing required field refuses at its pointer', ['/fields/tax_id required'], $refused(static fn () => $place([])));
pin('so does a blank one', ['/fields/tax_id required'], $refused(static fn () => $place(['tax_id' => '   '])));
pin('a value over its max refuses', ['/fields/tax_id maxLength'], $refused(static fn () => $place(['tax_id' => str_repeat('A', 13)])));
pin('nothing was written', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0]);

$placed = $place(['tax_id' => 'de123', 'gift' => '', 'unknown' => 'x', 'tax_ids' => ['a']]);
$order  = OrderStore::find($placed['order']->getId());
pin('the answers are kept with label and invoice flag; empty and unknown ones are not', ['tax_id' => ['label' => 'Tax number', 'value' => 'de123', 'invoice' => true]], $order->meta('_fields'));
OrderStore::cancel($order->getId());

$placed = $place(['tax_id' => '<i>x</i>&', 'gift' => 'Hi']);
$order  = OrderStore::find($placed['order']->getId());
pin('markup is stored as typed text', ['<i>x</i>&', 'Hi', false], [$order->meta('_fields')['tax_id']['value'], $order->meta('_fields')['gift']['value'], $order->meta('_fields')['gift']['invoice']]);
OrderStore::cancel($order->getId());

$upper = static function (CheckoutStarting $e): void {
    $e->setField('tax_id', strtoupper($e->field('tax_id')));
};
osc_add_hook('cart_checkout_before', $upper);
$placed = $place(['tax_id' => 'de123']);
$order  = OrderStore::find($placed['order']->getId());
pin('setField() normalises what is stored', 'DE123', $order->meta('_fields')['tax_id']['value']);
OrderStore::cancel($order->getId());
osc_remove_hook('cart_checkout_before', $upper);

$cut = new CheckoutStarting(['fields' => ['tax_id' => 'A']], [['id' => 'tax_id', 'max' => 4]]);
$cut->setField('tax_id', "  ABCDEFG\n");
pin('setField() keeps one line within the field\'s max', 'ABCD', $cut->field('tax_id'));
check('setField() on a field the checkout does not ask for throws', $bad(static fn () => $cut->setField('other', 'x')));

$refuse = static function (CheckoutStarting $e): void {
    if (!preg_match('/^[A-Z]{2}[0-9]+$/', $e->field('tax_id'))) {
        $e->refuse('/fields/tax_id', 'invalid', 'That tax number is not valid.');
    }
};
osc_add_hook('cart_checkout_before', $refuse);
$before = (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0];
pin('a listener refuses with the field\'s pointer', ['/fields/tax_id invalid'], $refused(static fn () => $place(['tax_id' => 'nope'])));
pin('and no order is written', $before, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0]);
osc_remove_hook('cart_checkout_before', $refuse);
osc_remove_filter('checkout_fields', $asks);

$placed = $checkout->place($tom, $cart, $address, 'standard', 'fake', '', null, ['tax_id' => 'x']);
pin('with no fields asked, an answer is dropped and nothing is stored', null, OrderStore::find($placed['order']->getId())->meta('_fields'));

exit(harness_result());
