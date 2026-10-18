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
 * Goods stock: a paid order takes its stock once, however often the callback comes; a refund
 * or a cancel gives it back; bank transfer and cash on delivery orders take it at placement,
 * refuse when short, and the daily job cancels them once unpaid past the hold; two callbacks
 * racing for the last unit leave one order oversold and the stock at zero; untracked stock is
 * never touched; the cash on delivery gateway and its fee line.
 * Usage: php tests/billing-stock.php
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
use mindstellar\billing\OrderStock;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\exception\InvalidException;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_billing_stock');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach ([
    'enabled_users'                => '1',
    'language'                     => 'en_US',
    'currency'                     => 'USD',
    'billing_enabled'              => '1',
    'billing_currency'             => 'USD',
    'billing_receipt_email'        => '0',
    'billing_offline_enabled'      => '1',
    'billing_offline_instructions' => 'Pay to account 123',
    'billing_cod_enabled'          => '1',
    'billing_cod_fee'              => '2000000',
] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

final class StockCardGateway implements PaymentGateway
{
    public function getId(): string
    {
        return 'card';
    }

    public function getName(): string
    {
        return 'Card';
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
        return CallbackResult::paid((int) $request['order'], (string) $request['ref'], (int) $request['amount'], 'USD');
    }
}
PaymentGatewayRegistry::getInstance()->register(new StockCardGateway());

$m       = static fn (float $units): int => (int) round($units * 1000000);
$tee     = seed_item($admin, $cat, $sue, 'Tee', 10.0);
$mug     = seed_item($admin, $cat, $sue, 'Mug', 5.0);
[$a, $r] = VariantStore::saveSet($tee, [
    ['options' => ['Size' => 'M'], 'fields' => ['i_stock' => 5, 'i_price' => $m(10)]],
    ['options' => ['Size' => 'L'], 'fields' => ['i_stock' => 1, 'i_price' => $m(10)]],
]);
$u        = VariantStore::saveDefault($mug, ['i_stock' => null]);
$stock    = static fn (int $variant): ?string => $admin->query("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $variant")->fetch_row()[0];
$setStock = static function (int $variant, int $to) use ($admin, $p): void {
    $admin->query("UPDATE {$p}t_item_variant SET i_stock = $to WHERE pk_i_id = $variant");
};
$address  = ['name' => 'Tom', 'phone' => '5550100', 'line1' => '1 Main St', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '73301', 'country' => 'US'];
$checkout = new GoodsCheckout();
$place    = static fn (array $lines, string $gateway) => $checkout->place($tom, $lines, $address, 'standard', $gateway)['order'];
$refused  = static function (callable $fn): array {
    try {
        $fn();
    } catch (InvalidException $e) {
        return array_map(static fn (array $x): string => $x['pointer'] . ' ' . $x['code'], $e->errors());
    }

    return ['not refused'];
};
$callback = static fn (Order $order): CallbackResult => Billing::handleCallback('card', ['order' => $order->getId(), 'ref' => 'pay-' . $order->getId(), 'amount' => $order->getAmount()]);

harness_section('a card order takes its stock when paid, once');
$order = $place([['variant' => $a, 'qty' => 2]], 'card');
pin('placing takes nothing', ['5', null], [$stock($a), OrderStore::find($order->getId())->meta(OrderStock::TAKEN)]);
pin('the paid callback takes it', [CallbackResult::OUTCOME_PAID, '3'], [$callback($order)->getOutcome(), $stock($a)]);
$lineId = OrderLineStore::itemLines($order->getId())[0]['id'];
pin('and records what it took', [$lineId => 2], OrderStore::find($order->getId())->meta(OrderStock::TAKEN));
$callback($order);
pin('the same callback again takes nothing more', '3', $stock($a));
pin('neither does marking it paid by hand', [false, '3'], [Billing::markPaid(OrderStore::find($order->getId())), $stock($a)]);
pin('take() on an order that holds its stock takes nothing', [[], '3'], [OrderStock::take($order->getId()), $stock($a)]);
pin('a refund gives it back', [true, '5', null], [Billing::refund(OrderStore::find($order->getId())), $stock($a), OrderStore::find($order->getId())->meta(OrderStock::TAKEN)]);
pin('once', [false, '5'], [Billing::refund(OrderStore::find($order->getId())), $stock($a)]);
pin('release() of an order holding nothing gives nothing', [false, '5'], [OrderStock::release($order->getId()), $stock($a)]);

harness_section('untracked stock');
$order = $place([['variant' => $u, 'qty' => 3]], 'card');
$callback($order);
pin('a paid order leaves an untracked variant untracked', [null, []], [$stock($u), OrderStore::find($order->getId())->meta(OrderStock::TAKEN)]);
Billing::refund(OrderStore::find($order->getId()));
pin('and so does its refund', null, $stock($u));
$held = $place([['variant' => $u, 'qty' => 2]], 'offline');
pin('a held order of untracked stock is placed and touches nothing', [Order::STATUS_PENDING, null], [$held->getStatus(), $stock($u)]);
OrderStore::cancel($held->getId());

harness_section('bank transfer and cash on delivery hold stock from placement');
$held = $place([['variant' => $a, 'qty' => 2]], 'offline');
pin('a bank transfer order takes its stock when placed', ['3', true], [$stock($a), is_array(OrderStore::find($held->getId())->meta(OrderStock::TAKEN))]);
pin('marking it paid takes nothing more', [true, '3'], [Billing::markPaid(OrderStore::find($held->getId())), $stock($a)]);
pin('its refund gives it back', [true, '5'], [Billing::refund(OrderStore::find($held->getId())), $stock($a)]);
$cod = $place([['variant' => $a, 'qty' => 1]], 'cod');
pin('a cash on delivery order takes its stock when placed', '4', $stock($a));
pin('cancelling it gives it back', [true, '5'], [OrderStore::cancel($cod->getId()), $stock($a)]);
pin('once', [false, '5'], [OrderStore::cancel($cod->getId()), $stock($a)]);

$orders   = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order")->fetch_row()[0];
$lineRows = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order_line")->fetch_row()[0];
$snatch   = static function () use ($r): void {
    VariantStore::adjustStock($r, -1);
};
osc_add_hook('cart_checkout_before', $snatch);
$before = [$orders(), $lineRows()];
pin('a held order short of stock at placement is refused on its line', ['/lines/1 out_of_stock'], $refused(static fn () => $place([['variant' => $a, 'qty' => 1], ['variant' => $r, 'qty' => 1]], 'offline')));
osc_remove_hook('cart_checkout_before', $snatch);
pin('and writes nothing: no order, no lines, no stock taken', [$before, '5', '0'], [[$orders(), $lineRows()], $stock($a), $stock($r)]);
$setStock($r, 1);

harness_section('two callbacks for the last unit');
$first  = $place([['variant' => $r, 'qty' => 1]], 'card');
$second = $place([['variant' => $r, 'qty' => 1]], 'card');
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$admin->begin_transaction();
$admin->query("SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $r FOR UPDATE");
$payers = [];
$outs   = [];
foreach ([$first, $second] as $i => $o) {
    $payers[] = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/lib/order-pay-child.php', $dbName, (string) $o->getId()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $outs[$i] = $pipes;
}
$watch   = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $dbName, (int) ini_get('mysqli.default_port'));
$waiting = 0;
for ($try = 0; $try < 400 && $waiting < 2; $try++) {
    usleep(50000);
    $waiting = (int) $watch->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = '{$dbName}' AND COMMAND = 'Query' AND INFO LIKE 'UPDATE {$p}t_item_variant%'")->fetch_row()[0];
}
$watch->close();
$admin->commit();
$printed = '';
foreach ($payers as $i => $proc) {
    $printed .= stream_get_contents($outs[$i][1]) . stream_get_contents($outs[$i][2]);
    proc_close($proc);
}
pin('both callbacks waited on the row', 2, $waiting);
pin('one order gets the unit, the other is marked short, neither throws', [1, 1, 0], [substr_count($printed, 'taken'), substr_count($printed, 'short'), substr_count($printed, 'threw')]);
pin('the stock ends at 0', '0', $stock($r));
$both = [OrderStore::find($first->getId()), OrderStore::find($second->getId())];
pin('both orders are paid', [true, true], [$both[0]->isPaid(), $both[1]->isPaid()]);
$shortOne = $both[0]->meta(OrderStock::SHORT) !== null ? $both[0] : $both[1];
pin('the short line is recorded with its quantity', [OrderLineStore::itemLines($shortOne->getId())[0]['id'] => 1], $shortOne->meta(OrderStock::SHORT));
pin('refunding the short order gives back nothing', [true, '0'], [Billing::refund($shortOne), $stock($r)]);
$setStock($r, 1);

$race = static function (array $runs, string $lock, string $waitLike) use ($admin, $p, $dbName): array {
    $admin->begin_transaction();
    $admin->query($lock);
    $procs = [];
    $pipes = [];
    foreach ($runs as $i => [$orderId, $action]) {
        $procs[$i] = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/lib/order-pay-child.php', $dbName, (string) $orderId, $action], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        $pipes[$i] = $pp;
    }
    $watch   = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $dbName, (int) ini_get('mysqli.default_port'));
    $waiting = 0;
    for ($try = 0; $try < 200 && $waiting < count($runs); $try++) {
        usleep(50000);
        $waiting = (int) $watch->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = '{$dbName}' AND COMMAND = 'Query' AND INFO LIKE '{$waitLike}'")->fetch_row()[0];
    }
    $watch->close();
    $admin->commit();
    $printed = '';
    foreach ($procs as $i => $proc) {
        $printed .= stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
        proc_close($proc);
    }

    return [$waiting, $printed];
};
$lockOrder = static fn (Order $o): string => "SELECT s_meta FROM {$p}t_billing_order WHERE pk_i_id = {$o->getId()} FOR UPDATE";

harness_section('two releases of one order');
$held = $place([['variant' => $a, 'qty' => 2]], 'offline');
[$waiting, $printed] = $race([[$held->getId(), 'release'], [$held->getId(), 'release']], $lockOrder($held), "SELECT s_meta FROM %t_billing_order%");
pin('both waited on the order row', 2, $waiting);
pin('one gives the stock back, the other finds nothing', [1, 1, 0], [substr_count($printed, 'released'), substr_count($printed, 'none'), substr_count($printed, 'threw')]);
pin('the stock is back once', '5', $stock($a));
OrderStore::cancel($held->getId());

harness_section('a payment and a cancel on one order');
foreach (['offline', 'card'] as $via) {
    $one = $place([['variant' => $a, 'qty' => 1]], $via);
    [$waiting, $printed] = $race([[$one->getId(), 'pay'], [$one->getId(), 'cancel']], $lockOrder($one), "UPDATE %t_billing_order%");
    $now = OrderStore::find($one->getId());
    pin("$via: both waited on the order row", 2, $waiting);
    pin("$via: exactly one wins, neither throws", [1, 0], [substr_count($printed, 'taken') + substr_count($printed, 'cancelled'), substr_count($printed, 'threw')]);
    pin("$via: the stock matches the winner", $now->isPaid() ? '4' : '5', $stock($a));
    if ($now->isPaid()) {
        Billing::refund($now);
    }
    pin("$via: and is whole again after", '5', $stock($a));
}

harness_section('two orders taking the same two variants in opposite order');
$setStock($r, 5);
$ar = $place([['variant' => $a, 'qty' => 1], ['variant' => $r, 'qty' => 1]], 'card');
$ra = $place([['variant' => $r, 'qty' => 1], ['variant' => $a, 'qty' => 1]], 'card');
$lockBoth = "SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id IN ($a, $r) FOR UPDATE";
[$waiting, $printed] = $race([[$ar->getId(), 'pay'], [$ra->getId(), 'pay']], $lockBoth, "UPDATE {$p}t_item_variant%");
pin('both waited on a variant', 2, $waiting);
pin('both take their stock, neither deadlocks', [2, 0], [substr_count($printed, 'taken'), substr_count($printed, 'threw')]);
pin('each variant lost two', ['3', '3'], [$stock($a), $stock($r)]);
[$waiting, $printed] = $race([[$ar->getId(), 'release'], [$ra->getId(), 'release']], $lockBoth, "UPDATE {$p}t_item_variant%");
pin('giving both back does not deadlock either', [2, 2, 0], [$waiting, substr_count($printed, 'released'), substr_count($printed, 'threw')]);
pin('and the stock is whole', ['5', '5'], [$stock($a), $stock($r)]);
$setStock($r, 1);

harness_section('the release job');
$age = static function (Order $order, int $days) use ($admin, $p): void {
    $at = date('Y-m-d H:i:s', time() - $days * 86400);
    $admin->query("UPDATE {$p}t_billing_order SET dt_date = '$at' WHERE pk_i_id = {$order->getId()}");
};
$oldBank   = $place([['variant' => $a, 'qty' => 1]], 'offline');
$oldCod    = $place([['variant' => $a, 'qty' => 1]], 'cod');
$newBank   = $place([['variant' => $a, 'qty' => 1]], 'offline');
$oldCard   = $place([['variant' => $a, 'qty' => 1]], 'card');
$oldPaid   = $place([['variant' => $a, 'qty' => 1]], 'offline');
$oldCredit = OrderStore::create($tom, 'offline', $m(5), 'USD', 50);
Billing::markPaid($oldPaid);
foreach ([$oldBank, $oldCod, $oldCard, $oldPaid, $oldCredit] as $o) {
    $age($o, 8);
}
$age($newBank, 6);
pin('the held orders took 4 of 5', '1', $stock($a));
$cancelled = [];
$onCancel  = static function ($orderId) use (&$cancelled): void {
    $cancelled[] = (int) $orderId;
};
osc_add_hook('billing_order_cancelled', $onCancel);
pin('one run cancels the two held orders past the 7-day hold', 2, Billing::releaseHeld());
osc_remove_hook('billing_order_cancelled', $onCancel);
$status = static fn (Order $o): string => OrderStore::find($o->getId())->getStatus();
pin('only those', ['cancelled', 'cancelled', 'pending', 'pending', 'paid', 'pending'], array_map($status, [$oldBank, $oldCod, $newBank, $oldCard, $oldPaid, $oldCredit]));
pin('each announced once', [$oldBank->getId(), $oldCod->getId()], $cancelled);
pin('and their stock is back', '3', $stock($a));
pin('a second run finds nothing', 0, Billing::releaseHeld());
Preference::getInstance()->set('billing_hold_days', '5');
scratchdb_forget_cache();
osc_reset_preferences();
pin('a shorter hold reaches the newer order', [1, 'cancelled', '4'], [Billing::releaseHeld(), $status($newBank), $stock($a)]);
$n = harness_query_count(static fn () => OrderStore::heldBefore(Billing::HOLDS_STOCK, date('Y-m-d H:i:s'), 100));
pin('the batch is read in one query', $n < 0 ? $n : 1, $n);

osc_run_hook('register_jobs');
check('the job has a handler', osc_job_has_handler(Billing::JOB_RELEASE));
Billing::queueRelease();
Billing::queueRelease();
pin('the daily tick queues it once', 1, osc_job_count('pending', Billing::JOB_RELEASE));

harness_section('cash on delivery');
$pricer = new CartPricer();
$q      = $pricer->quote([['variant' => $u, 'qty' => 1]], ['gateway' => 'cod']);
pin('the fee is a fee line', [['id' => 'fee.cod', 'label' => 'Cash on delivery fee', 'amount' => $m(2), 'currency' => 'USD', 'meta' => []]], $q->extraLines('fee'));
pin('and in the total', [$m(2), $m(7)], [$q->totals()['fee'], $q->total()]);
pin('another gateway pays no fee', [[], $m(5)], [$pricer->quote([['variant' => $u, 'qty' => 1]], ['gateway' => 'offline'])->extraLines('fee'), $pricer->quote([['variant' => $u, 'qty' => 1]], ['gateway' => 'offline'])->total()]);
$placed = $checkout->place($tom, [['variant' => $u, 'qty' => 1]], $address, 'standard', 'cod');
$lines  = OrderLineStore::ofOrder($placed['order']->getId());
pin('a placed order carries the fee line and charges it', [$m(7), 'fee', 'Cash on delivery fee', $m(2)], [$placed['order']->getAmount(), end($lines)['kind'], end($lines)['title'], end($lines)['amount']]);
pin('the checkout tells the buyer what to pay', [CheckoutIntent::KIND_HTML, true, true], [
    $placed['next']->getKind(),
    str_contains($placed['next']->getPayload(), 'Pay 7.00 USD when it arrives.'),
    str_contains($placed['next']->getPayload(), 'Order #' . $placed['order']->getId()),
]);
Preference::getInstance()->set('billing_order_prefix', 'KC-<b>');
scratchdb_forget_cache();
osc_reset_preferences();
pin('the order number takes the prefix, cleaned', '#KC-b42', osc_billing_order_number(42));
$prefixed = OrderStore::find($placed['order']->getId());
$offline  = PaymentGatewayRegistry::getInstance()->get('offline')->createCheckout($prefixed)->getPayload();
pin('mails, receipts and the bank reference use it too', ['#KC-b' . $prefixed->getId(), true, true, true], [
    \mindstellar\billing\Receipts::number($prefixed),
    str_contains($offline, 'Reference: #KC-b' . $prefixed->getId()),
    str_contains(\mindstellar\billing\Receipts::emailBody(\mindstellar\billing\Receipts::vars($prefixed)), '#KC-b' . $prefixed->getId()),
    \mindstellar\api\serializer\OrderSerializer::number($prefixed->getId()) === osc_billing_order_number($prefixed->getId()),
]);
pin('a cash on delivery callback is ignored', CallbackResult::OUTCOME_IGNORED, Billing::handleCallback('cod', ['order' => $placed['order']->getId()])->getOutcome());
Preference::getInstance()->set('billing_cod_enabled', '0');
scratchdb_forget_cache();
osc_reset_preferences();
pin('switched off, it is not offered and adds no fee', [false, []], [isset(PaymentGatewayRegistry::getInstance()->available('USD')['cod']), $pricer->quote([['variant' => $u, 'qty' => 1]], ['gateway' => 'cod'])->extraLines('fee')]);
pin('and cannot be placed', ['/gateway unavailable'], $refused(static fn () => $place([['variant' => $u, 'qty' => 1]], 'cod')));

exit(harness_result());
