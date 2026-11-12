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
 * Partial refunds: by lines or by amount, recorded or through the gateway with its key; the
 * checks that refuse; stock given back once; the notes reaching the paid amount refund the order;
 * a full refund after partials issues one remainder note; and refundThroughGateway() after partials.
 * Usage: php tests/billing-partial-refund.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Document;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStock;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PartialRefundableGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\billing\RefundableGateway;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_billing_partial_refund');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Goods', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_receipt_email' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
require_once ABS_PATH . 'oc-includes/osclass/emails.php';
foreach (['osc_get_domain' => 'shop.test', 'osc_mailserver_name_from' => '', 'osc_mailserver_mail_from' => 'noreply@shop.test', 'osc_themes_path' => ABS_PATH . 'oc-content/themes/', 'osc_theme' => ''] as $fn => $value) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ' . var_export($value, true) . '; }');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($withIndex = false)
    {
        return 'https://shop.test/oc-admin/' . ($withIndex ? 'index.php' : '');
    }
}
osc_add_filter('init_send_mail', static fn ($mail) => new class (true) extends PHPMailer\PHPMailer\PHPMailer {
    public function send()
    {
        return true;
    }
});
Receipts::$mailer = static fn (array $params): bool => true;

/** A gateway double that refunds parts; each test sets its answer. */
final class PartPayGateway implements PartialRefundableGateway
{
    /** @var callable(Order,int,string):CallbackResult|null */
    public $answer = null;

    /** @var list<array{int,string}> */
    public array $calls = [];

    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return 'Part pay';
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
        return CheckoutIntent::redirect('https://example.test/pay');
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored();
    }

    public function refund(Order $order): CallbackResult
    {
        $this->calls[] = [$order->getAmount(), 'full'];

        return CallbackResult::refunded($order->getId(), 'full_' . $order->getId());
    }

    public function refundPart(Order $order, int $amount, string $key): CallbackResult
    {
        $this->calls[] = [$amount, $key];

        return ($this->answer)($order, $amount, $key);
    }
}

/** A gateway that refunds only whole orders. */
final class WholePayGateway implements RefundableGateway
{
    public int $calls = 0;

    public function getId(): string
    {
        return 'wholepay';
    }

    public function getName(): string
    {
        return 'Whole pay';
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
        return CheckoutIntent::redirect('https://example.test/pay');
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored();
    }

    public function refund(Order $order): CallbackResult
    {
        $this->calls++;

        return CallbackResult::refunded($order->getId(), 'whole_' . $order->getId());
    }
}

$gw = new PartPayGateway('partpay');
PaymentGatewayRegistry::getInstance()->register($gw);
$whole = new WholePayGateway();
PaymentGatewayRegistry::getInstance()->register($whole);

$m     = static fn (float $units): int => (int) round($units * 1_000_000);
$tee   = seed_item($admin, $cat, $sue, 'Tee', 10.0);
$mug   = seed_item($admin, $cat, $sue, 'Mug', 5.0);
$vTee  = VariantStore::saveDefault($tee, ['i_stock' => 10]);
$vMug  = VariantStore::saveDefault($mug, ['i_stock' => 10]);
$stock = static fn (int $variant): int => (int) $admin->query("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $variant")->fetch_row()[0];

/** Three tees at 10.00, a mug at 5.00, delivery 5.00 and tax 4.00 on top: 44.00, paid, stock taken. */
$order = static function (string $gateway = 'offline') use ($sue, $m, $tee, $mug, $vTee, $vMug): Order {
    $o = OrderStore::create($sue, $gateway, $m(44), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee × 3, Mug', '_address' => ['name' => 'Sue', 'line1' => '1 Main St', 'city' => 'Austin', 'country' => 'US']]);
    OrderLineStore::add($o->getId(), [
        ['kind' => 'item', 'item_id' => $tee, 'variant_id' => $vTee, 'title' => 'Tee', 'qty' => 3, 'unit' => $m(10), 'amount' => $m(30)],
        ['kind' => 'item', 'item_id' => $mug, 'variant_id' => $vMug, 'title' => 'Mug', 'qty' => 1, 'unit' => $m(5), 'amount' => $m(5)],
        ['kind' => 'shipping', 'title' => 'Standard delivery', 'qty' => 1, 'unit' => $m(5), 'amount' => $m(5)],
        ['kind' => 'tax', 'title' => 'VAT 10%', 'qty' => 1, 'unit' => $m(4), 'amount' => $m(4), 'meta' => ['id' => 'vat']],
    ]);
    OrderStock::take($o->getId());
    Billing::markPaid($o, 'ref-' . $o->getId());

    return OrderStore::find($o->getId());
};
$lineOf  = static function (Order $o, string $title): int {
    foreach (OrderLineStore::itemLines($o->getId()) as $line) {
        if ($line['title'] === $title) {
            return $line['id'];
        }
    }

    return 0;
};
$notes   = static fn (int $id): array => array_values(array_filter(DocumentStore::forOrder($id), static fn (Document $d): bool => $d->isCreditNote()));
$fresh   = static fn (Order $o): Order => OrderStore::find($o->getId());
$refunds = 0;
osc_add_hook('billing_order_refunded', static function () use (&$refunds): void {
    $refunds++;
});

harness_section('a recorded refund of one unit, back in stock');
$a     = $order();
$teeA  = $lineOf($a, 'Tee');
$mugA  = $lineOf($a, 'Mug');
$teeAt = $stock($vTee);
$r     = Billing::refundPart($a, 0, [$teeA => 1], 'Too small', true, false);
pin('it is refunded', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
$n = $notes($a->getId());
pin('one credit note for the unit with its tax share: 10.00 × 44/40', [1, $m(11)], [count($n), $n[0]->total ?? null]);
pin('tax on it is 4.00 × 11/44', [$m(1)], array_values(array_map(static fn (array $l): int => $l['amount'], array_filter($n[0]->data['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'tax'))));
pin('the reason and restock are on it', ['Too small', [$teeA => 1]], [$n[0]->data['refund']['reason'] ?? null, $n[0]->data['refund']['restocked'] ?? null]);
pin('the unit is back in stock', $teeAt + 1, $stock($vTee));
pin('the order holds two tees and the mug', [$teeA => 2, $mugA => 1], OrderStock::held($fresh($a)));
pin('the order stays paid', Order::STATUS_PAID, $fresh($a)->getStatus());
pin('no refunded hook yet', 0, $refunds);

harness_section('refusals change nothing');
$before = count($notes($a->getId()));
$r = Billing::refundPart($fresh($a), $m(34), [], 'x', false, false);
pin('more than is left, and how much is', [CallbackResult::OUTCOME_IGNORED, sprintf('At most %s is left to refund', \mindstellar\currency\Money::format($m(33), 'USD'))], [$r->getOutcome(), $r->getMessage()]);
pin('more units than are left', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($a), 0, [$teeA => 3], 'x', true, false)->getOutcome());
pin('no amount and no lines', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($a), 0, [], 'x', true, false)->getOutcome());
pin('a line of another order', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($a), $m(1), [999999 => 1], 'x', true, false)->getOutcome());
pin('offline cannot refund through a gateway', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($a), $m(1), [], 'x', false, true)->getOutcome());
pin('no note was issued', $before, count($notes($a->getId())));
$pack = OrderStore::create($sue, 'offline', $m(5), 'USD', 100);
Billing::markPaid($pack);
pin('a credit pack refunds whole only', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($pack), $m(1), [], 'x', false, false)->getOutcome());
$old = OrderStore::create($sue, 'offline', $m(8), 'USD', 0, ['_kind' => Order::KIND_GOODS]);
OrderStore::settle($old->getId(), Order::STATUS_PAID, 'old-ref');
pin('an order without an invoice refunds whole only', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($old), $m(1), [], 'x', false, false)->getOutcome());
$unpaid = OrderStore::create($sue, 'offline', $m(8), 'USD', 0, ['_kind' => Order::KIND_GOODS]);
pin('an unpaid order', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($unpaid, $m(1), [], 'x', false, false)->getOutcome());

harness_section('a unit kept by the buyer: refunded, not restocked');
$mugAt = $stock($vMug);
pin('it is refunded', CallbackResult::OUTCOME_REFUNDED, Billing::refundPart($fresh($a), 0, [$mugA => 1], 'Broken in transit', false, false)->getOutcome());
pin('the mug stays out of stock', $mugAt, $stock($vMug));
pin('and the order no longer holds it', [$teeA => 2], OrderStock::held($fresh($a)));
pin('the note records no restock', [], $notes($a->getId())[1]->data['refund']['restocked'] ?? null);

harness_section('a full refund after partials issues one remainder note');
$teeAt = $stock($vTee);
$mugAt = $stock($vMug);
check('the webhook refund settles it', Billing::refund($fresh($a)));
$n = $notes($a->getId());
pin('three notes, the last for the rest', [3, $m(44 - 11 - 5.5)], [count($n), $n[2]->total ?? null]);
pin('the notes add up to the paid amount', $m(44), DocumentStore::refundedTotal($a->getId()));
pin('the two tees left go back, the mug does not', [$teeAt + 2, $mugAt], [$stock($vTee), $stock($vMug)]);
check('a replayed webhook does nothing', !Billing::refund($fresh($a)));
pin('still three notes, stock still the same', [3, $teeAt + 2], [count($notes($a->getId())), $stock($vTee)]);
pin('a part refund of a refunded order is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($a), $m(1), [], 'x', false, false)->getOutcome());

harness_section('through the gateway, with its key');
$refunds = 0;
$b       = $order('partpay');
$teeB    = $lineOf($b, 'Tee');
$gw->answer = static fn (Order $o, int $amount, string $key): CallbackResult => CallbackResult::refundPending($o->getId(), 'processing', 're_' . md5($key));
$r = Billing::refundPart($b, $m(5), [], 'Late', false, true);
pin('a pending answer still issues the note', CallbackResult::OUTCOME_PENDING, $r->getOutcome());
pin('the gateway got the amount and the first key', [[$m(5), 'order:' . $b->getId() . ':refund:1']], $gw->calls);
$n = $notes($b->getId());
pin('the note carries the provider ref', ['re_' . md5('order:' . $b->getId() . ':refund:1'), $m(5)], [$n[0]->data['refund']['provider_ref'] ?? null, $n[0]->total ?? null]);
pin('an amount alone refunds no line', [], array_values(array_filter($n[0]->data['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'item')));

$gw->calls  = [];
$gw->answer = static fn (Order $o): CallbackResult => CallbackResult::ignored('card closed');
$r = Billing::refundPart($fresh($b), $m(2), [], 'x', false, true);
pin('a refusal is passed on', ['ignored', 'card closed'], [$r->getOutcome(), $r->getMessage()]);
$gw->answer = static function (): CallbackResult {
    throw new RuntimeException('network down');
};
$r = Billing::refundPart($fresh($b), $m(2), [], 'x', false, true);
pin('a throw changes nothing', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$gw->answer = static fn (Order $o, int $amount, string $key): CallbackResult => CallbackResult::refunded($o->getId(), 're_ok');
Billing::refundPart($fresh($b), $m(2), [], 'x', false, true);
$k = 'order:' . $b->getId() . ':refund:';
pin('a refused try gets a new key, a retry after a throw keeps it', [$k . '2', $k . '3', $k . '3'], array_column($gw->calls, 1));
pin('two notes so far', [2, $m(7)], [count($notes($b->getId())), DocumentStore::refundedTotal($b->getId())]);
$gw->answer = static fn (Order $o): CallbackResult => CallbackResult::refunded($o->getId() + 1, 're_x');
pin('an answer for another order is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundPart($fresh($b), $m(1), [], 'x', false, true)->getOutcome());
pin('and issues no note', 2, count($notes($b->getId())));

harness_section('refundThroughGateway() after partials sends only the rest');
$gw->calls  = [];
$gw->answer = static fn (Order $o, int $amount, string $key): CallbackResult => CallbackResult::refunded($o->getId(), 're_rest');
$r = Billing::refundThroughGateway($fresh($b), $accepted);
pin('the rest goes through refundPart, not refund', [[$m(44 - 7), $k . '5']], $gw->calls);
pin('accepted, and the order is refunded', [true, Order::STATUS_REFUNDED], [$accepted, $fresh($b)->getStatus()]);
pin('the notes add up to the paid amount', $m(44), DocumentStore::refundedTotal($b->getId()));
pin('the refunded hook ran once', 1, $refunds);
pin('stock is back once: all the tees, the mug', [], OrderStock::held($fresh($b)));

$c = $order('wholepay');
Billing::refundPart($c, $m(4), [], 'x', false, false);
$r = Billing::refundThroughGateway($fresh($c), $accepted);
pin('a whole-only gateway is not asked to refund the whole order again', [CallbackResult::OUTCOME_IGNORED, false, 0], [$r->getOutcome(), $accepted, $whole->calls]);
$d = $order('wholepay');
check('with no partials it refunds as before', Billing::refundThroughGateway($fresh($d))->getOutcome() === CallbackResult::OUTCOME_REFUNDED && $whole->calls === 1);
pin('with one note for the whole', [1, $m(44)], [count($notes($d->getId())), DocumentStore::refundedTotal($d->getId())]);

harness_section('lines with their own tax refund that tax, not a share of the order\'s');
$lt = OrderStore::create($sue, 'offline', $m(41), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee × 3, Mug']);
OrderLineStore::add($lt->getId(), [
    ['kind' => 'item', 'item_id' => $tee, 'variant_id' => $vTee, 'title' => 'Tee', 'qty' => 3, 'unit' => $m(10), 'amount' => $m(30), 'meta' => ['tax' => ['included' => false, 'base' => $m(30), 'amount' => $m(6), 'code' => null, 'parts' => [['tax' => 'vat.20', 'label' => 'VAT 20%', 'bp' => 2000, 'amount' => $m(6)]]]]],
    ['kind' => 'item', 'item_id' => $mug, 'variant_id' => $vMug, 'title' => 'Mug', 'qty' => 1, 'unit' => $m(5), 'amount' => $m(5)],
    ['kind' => 'tax', 'title' => 'VAT 20%', 'qty' => 1, 'unit' => $m(6), 'amount' => $m(6), 'meta' => ['id' => 'vat.20']],
]);
Billing::markPaid($lt, 'ref-lt');
Billing::refundPart($fresh($lt), 0, [$lineOf($lt, 'Tee') => 1], 'Return', false, false);
pin('the amount is the unit and its own tax: 10.00 + 2.00, not 10.00 × 41/35', $m(12), $notes($lt->getId())[0]->total ?? null);

harness_section('partials that reach the paid amount refund the order');
$refunds = 0;
$e       = $order();
$teeE    = $lineOf($e, 'Tee');
$mugE    = $lineOf($e, 'Mug');
$teeAt   = $stock($vTee);
$mugAt   = $stock($vMug);
Billing::refundPart($e, 0, [$teeE => 3, $mugE => 1], 'Returned', true, false);
pin('the goods with their tax are 38.50', $m(38.5), DocumentStore::refundedTotal($e->getId()));
pin('still paid', Order::STATUS_PAID, $fresh($e)->getStatus());
$r = Billing::refundPart($fresh($e), $m(5.5), [], 'Delivery', false, false);
pin('the last part refunds the order', [CallbackResult::OUTCOME_REFUNDED, Order::STATUS_REFUNDED, 1], [$r->getOutcome(), $fresh($e)->getStatus(), $refunds]);
$last = $notes($e->getId())[1] ?? null;
pin('its tax is the exact remainder', [$m(0.5)], array_values(array_map(static fn (array $l): int => $l['amount'], array_filter($last?->data['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'tax'))));
pin('every unit went back once', [$teeAt + 3, $mugAt + 1], [$stock($vTee), $stock($vMug)]);
check('a webhook refund after it does nothing', !Billing::refund($fresh($e)));
pin('two notes, no third', 2, count($notes($e->getId())));
pin('the hook did not run again', 1, $refunds);

harness_section('closing a shipped order by amount keeps shipped units out of stock');
$sh    = $order();
$teeSh = $lineOf($sh, 'Tee');
$mugSh = $lineOf($sh, 'Mug');
check('two tees and the mug go out', \mindstellar\billing\Shipments::add($sh->getId(), [$teeSh => 2, $mugSh => 1], 'DHL', 'S1', '') !== null);
$teeAt = $stock($vTee);
$mugAt = $stock($vMug);
Billing::refundPart($fresh($sh), 0, [$teeSh => 1], 'Returned', true, false);
Billing::refundPart($fresh($sh), $m(5), [], 'Goodwill', false, false);
$rest = $m(44) - DocumentStore::refundedTotal($sh->getId());
pin('the last amount refunds the order', Order::STATUS_REFUNDED, Billing::refundPart($fresh($sh), $rest, [], 'Rest', true, false)->getOutcome() === CallbackResult::OUTCOME_REFUNDED ? $fresh($sh)->getStatus() : null);
pin('the returned tee and the one never sent go back, the kept tee and the mug do not', [$teeAt + 2, $mugAt], [$stock($vTee), $stock($vMug)]);
pin('the order holds nothing', [], OrderStock::held($fresh($sh)));

$fu    = $order();
$teeFu = $lineOf($fu, 'Tee');
\mindstellar\billing\Shipments::add($fu->getId(), [$teeFu => 2], 'DHL', 'S2', '');
$teeAt = $stock($vTee);
$mugAt = $stock($vMug);
check('a full refund with no partials settles it', Billing::refund($fresh($fu)));
pin('only the tee and the mug never sent go back', [$teeAt + 1, $mugAt + 1], [$stock($vTee), $stock($vMug)]);

exit(harness_result());
