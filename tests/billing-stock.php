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
 * Goods stock: every order takes its stock once at placement and refuses when short, however
 * often the paid callback comes; a refund, a cancel or a failed payment gives it back; a card
 * order still unpaid when its hold ends is cancelled, and a payment after that oversells nothing;
 * the daily job cancels bank transfer and cash on delivery orders once unpaid past the hold; two
 * callbacks racing for the last unit of orders that hold no stock leave one oversold and the
 * stock at zero; untracked stock is never touched; the cash on delivery gateway and its fee line.
 * Usage: php tests/billing-stock.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

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
use mindstellar\database\Db;
use mindstellar\exception\InvalidException;
use mindstellar\listing\event\VariantStockChanged;
use mindstellar\listing\ProductAlerts;
use mindstellar\listing\VariantStore;

$admin = api_admin_boot('osc_billing_stock');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Tees', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$kim = seed_user($admin, 'kim', 'kim@example.test');
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
    'billing_open_hold_orders'     => '0',
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
        if (!empty($request['failed'])) {
            return CallbackResult::failed((int) $request['order'], 'declined');
        }

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

harness_section('a card order takes its stock when placed, once');
$order  = $place([['variant' => $a, 'qty' => 2]], 'card');
$lineId = OrderLineStore::itemLines($order->getId())[0]['id'];
pin('placing takes it and records what it took', ['3', [$lineId => 2]], [$stock($a), OrderStore::find($order->getId())->meta(OrderStock::TAKEN)]);
pin('the paid callback takes nothing more', [CallbackResult::OUTCOME_PAID, '3'], [$callback($order)->getOutcome(), $stock($a)]);
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

// An order placed before card orders took their stock at placement holds none.
$noStock = static function (Order $order): Order {
    OrderStock::release($order->getId());

    return $order;
};

harness_section('two callbacks for the last unit');
$first  = $noStock($place([['variant' => $r, 'qty' => 1]], 'card'));
$second = $noStock($place([['variant' => $r, 'qty' => 1]], 'card'));
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$child  = __DIR__ . '/lib/order-pay-child.php';
[$waiting, $out] = race($admin, "SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id = $r FOR UPDATE", $dbName, [[$child, [$first->getId()]], [$child, [$second->getId()]]], "%{$p}t_item_variant%");
$printed = implode('', $out);
pin('both callbacks waited on the row', 2, $waiting);
pin('one order gets the unit, the other is marked short, neither throws', [1, 1, 0], [substr_count($printed, 'taken'), substr_count($printed, 'short'), substr_count($printed, 'threw')]);
pin('the stock ends at 0', '0', $stock($r));
$both = [OrderStore::find($first->getId()), OrderStore::find($second->getId())];
pin('both orders are paid', [true, true], [$both[0]->isPaid(), $both[1]->isPaid()]);
$shortOne = $both[0]->meta(OrderStock::SHORT) !== null ? $both[0] : $both[1];
$otherOne = $shortOne === $both[0] ? $both[1] : $both[0];
$oversold = static fn (int $orderId): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_log WHERE s_section = 'billing' AND s_action = 'oversold' AND fk_i_id = $orderId")->fetch_row()[0];
pin('the short order logs billing/oversold once, the other order logs nothing', [1, 0], [$oversold($shortOne->getId()), $oversold($otherOne->getId())]);
pin('the short line is recorded with its quantity', [OrderLineStore::itemLines($shortOne->getId())[0]['id'] => 1], $shortOne->meta(OrderStock::SHORT));
pin('OrderStock::short reads it back, and nothing for an order that was not short', [[OrderLineStore::itemLines($shortOne->getId())[0]['id'] => 1], []], [OrderStock::short($shortOne), OrderStock::short($otherOne)]);
pin('refunding the short order gives back nothing', [true, '0'], [Billing::refund($shortOne), $stock($r)]);
$setStock($r, 1);

$lockOrder = static fn (Order $o): string => "SELECT s_meta FROM {$p}t_billing_order WHERE pk_i_id = {$o->getId()} FOR UPDATE";

harness_section('two releases of one order');
$held = $place([['variant' => $a, 'qty' => 2]], 'offline');
[$waiting, $out] = race($admin, $lockOrder($held), $dbName, [[$child, [$held->getId(), 'release']], [$child, [$held->getId(), 'release']]], "SELECT s_meta FROM %t_billing_order%");
$printed = implode('', $out);
pin('both waited on the order row', 2, $waiting);
pin('one gives the stock back, the other finds nothing', [1, 1, 0], [substr_count($printed, 'released'), substr_count($printed, 'none'), substr_count($printed, 'threw')]);
pin('the stock is back once', '5', $stock($a));
OrderStore::cancel($held->getId());

harness_section('a payment and a cancel on one order');
foreach (['offline', 'card'] as $via) {
    $one = $place([['variant' => $a, 'qty' => 1]], $via);
    [$waiting, $out] = race($admin, $lockOrder($one), $dbName, [[$child, [$one->getId(), 'pay']], [$child, [$one->getId(), 'cancel']]], "%t_billing_order%");
$printed = implode('', $out);
    $now = OrderStore::find($one->getId());
    pin("$via: both waited on the order row", 2, $waiting);
    pin("$via: exactly one wins, neither throws", [1, 0], [substr_count($printed, 'taken') + substr_count($printed, 'cancelled'), substr_count($printed, 'threw')]);
    pin("$via: the stock matches the winner", $now->isPaid() ? '4' : '5', $stock($a));
    if ($now->isPaid()) {
        Billing::refund($now);
    }
    pin("$via: and is whole again after", '5', $stock($a));
}

harness_section('a cancel while a payment of the same order commits');
$held  = $place([['variant' => $a, 'qty' => 1]], 'offline');
[$waiting, $out] = race($admin, "UPDATE {$p}t_billing_order SET s_status = 'paid', dt_paid_date = NOW() WHERE pk_i_id = {$held->getId()}", $dbName, [[$child, [$held->getId(), 'cancel']]], "%{$p}t_billing_order%");
pin('the cancel waited on the order row', 1, $waiting);
pin('it finds the order paid and keeps it, without an error', "kept\n", $out[0]);
pin('the order stays paid and keeps its stock', [true, '4'], [OrderStore::find($held->getId())->isPaid(), $stock($a)]);
Billing::refund(OrderStore::find($held->getId()));

harness_section('a cash on delivery order placed while a payment takes the same variant');
[$waiting, $out] = race($admin, "UPDATE {$p}t_item_variant SET i_stock = i_stock - 1 WHERE pk_i_id = $a", $dbName, [[$child, [$a, 'place', $tom, 'cod']]], "%{$p}t_item_variant%");
pin('the placing waited on the variant', 1, $waiting);
pin('it is placed without an error', "placed\n", $out[0]);
pin('and both units are gone', '3', $stock($a));
OrderStore::cancel((int) $admin->query("SELECT MAX(pk_i_id) FROM {$p}t_billing_order")->fetch_row()[0]);
$setStock($a, 5);

harness_section('two orders taking the same two variants in opposite order');
$setStock($r, 5);
$ar = $noStock($place([['variant' => $a, 'qty' => 1], ['variant' => $r, 'qty' => 1]], 'card'));
$ra = $noStock($place([['variant' => $r, 'qty' => 1], ['variant' => $a, 'qty' => 1]], 'card'));
$lockBoth = "SELECT i_stock FROM {$p}t_item_variant WHERE pk_i_id IN ($a, $r) FOR UPDATE";
[$waiting, $out] = race($admin, $lockBoth, $dbName, [[$child, [$ar->getId(), 'pay']], [$child, [$ra->getId(), 'pay']]], "%{$p}t_item_variant%");
$printed = implode('', $out);
pin('both waited on a variant', 2, $waiting);
pin('both take their stock, neither deadlocks', [2, 0], [substr_count($printed, 'taken'), substr_count($printed, 'threw')]);
pin('each variant lost two', ['3', '3'], [$stock($a), $stock($r)]);
[$waiting, $out] = race($admin, $lockBoth, $dbName, [[$child, [$ar->getId(), 'release']], [$child, [$ra->getId(), 'release']]], "%{$p}t_item_variant%");
$printed = implode('', $out);
pin('giving both back does not deadlock either', [2, 2, 0], [$waiting, substr_count($printed, 'released'), substr_count($printed, 'threw')]);
pin('and the stock is whole', ['5', '5'], [$stock($a), $stock($r)]);
$setStock($r, 1);

harness_section('a back-in-stock alert is queued once the change commits');
$alertJobs = static fn (): int => (int) Db::scalar("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = ?", [ProductAlerts::JOB]);
$before    = $alertJobs();
$inside    = null;
try {
    Db::transaction(static function () use ($a, $tee, $alertJobs, &$inside): void {
        ProductAlerts::stockChanged(new VariantStockChanged($a, $tee, 0, 2));
        $inside = $alertJobs();

        throw new RuntimeException('roll back');
    });
} catch (RuntimeException $e) {
}
pin('nothing is queued inside the transaction, nor after a rollback', [$before, $before], [$inside, $alertJobs()]);
Db::transaction(static function () use ($a, $tee, $alertJobs, &$inside): void {
    ProductAlerts::stockChanged(new VariantStockChanged($a, $tee, 0, 2));
    $inside = $alertJobs();
});
pin('and one job after a commit', [$before, $before + 1], [$inside, $alertJobs()]);

harness_section('a declined card payment');
$declined = $place([['variant' => $a, 'qty' => 1]], 'card');
$failed   = [];
$onFail   = static function ($orderId, $userId) use (&$failed): void {
    $failed[] = [(int) $orderId, (int) $userId];
};
osc_add_hook('billing_order_failed', $onFail);
Billing::handleCallback('card', ['order' => $declined->getId(), 'failed' => 1]);
Billing::handleCallback('card', ['order' => $declined->getId(), 'failed' => 1]);
osc_remove_hook('billing_order_failed', $onFail);
pin('the order fails and billing_order_failed fires once with its buyer', ['failed', [[$declined->getId(), $declined->getUserId()]]], [OrderStore::find($declined->getId())->getStatus(), $failed]);
pin('and its stock is back', ['5', null], [$stock($a), OrderStore::find($declined->getId())->meta(OrderStock::TAKEN)]);

harness_section('a card order holds its stock for a short time');
$setStock($r, 1);
$placeAs = static fn (int $user, string $gateway) => $checkout->place($user, [['variant' => $r, 'qty' => 1]], $address, 'standard', $gateway)['order'];
$mine    = $placeAs($tom, 'card');
pin('the last unit is taken by the first card order', '0', $stock($r));
pin('a second buyer is refused at placement, before paying', ['/lines/0 out_of_stock'], $refused(static fn () => $placeAs($kim, 'card')));
pin('and with another gateway too', ['/lines/0 out_of_stock'], $refused(static fn () => $placeAs($kim, 'offline')));
$holdJob = static fn (Order $o): ?array => Db::selectOne("SELECT s_payload, TIMESTAMPDIFF(SECOND, NOW(), dt_next_run) AS i_in FROM {$p}t_job_queue WHERE s_type = ? AND s_unique = ?", [Billing::JOB_CARD_HOLD, (string) $o->getId()]);
$job     = $holdJob($mine);
pin('placing queues its hold end, 15 minutes out by default', [['order' => $mine->getId()], true], [json_decode((string) ($job['s_payload'] ?? ''), true), abs((int) ($job['i_in'] ?? 0) - 900) <= 5]);
$bank = $checkout->place($kim, [['variant' => $a, 'qty' => 1]], $address, 'standard', 'offline')['order'];
pin('a bank transfer order queues none', null, $holdJob($bank));
OrderStore::cancel($bank->getId());
$setStock($r, 0);
$cancelled = [];
$onCancel  = static function ($orderId) use (&$cancelled): void {
    $cancelled[] = (int) $orderId;
};
osc_add_hook('billing_order_cancelled', $onCancel);
osc_run_hook('register_jobs');
check('the hold job has a handler', osc_job_has_handler(Billing::JOB_CARD_HOLD));
pin('when the hold ends unpaid, the order is cancelled and its stock is back', [true, 'cancelled', '1'], [Billing::expireCardHold($mine->getId()), OrderStore::find($mine->getId())->getStatus(), $stock($r)]);
pin('announced once, with no cancel mail to the buyer', [[$mine->getId()], true], [$cancelled, OrderStore::find($mine->getId())->meta('_mail_cancelled') !== null]);
pin('a second run changes nothing', [false, '1'], [Billing::expireCardHold($mine->getId()), $stock($r)]);
$kims = $placeAs($kim, 'card');
pin('the unit goes to the next buyer', '0', $stock($r));
$late     = $callback(OrderStore::find($mine->getId()));
$logged   = static fn (string $action, int $orderId): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_log WHERE s_section = 'billing' AND s_action = '$action' AND fk_i_id = $orderId")->fetch_row()[0];
pin('a payment after the hold leaves the order cancelled and takes nothing', ['cancelled', '0', null], [OrderStore::find($mine->getId())->getStatus(), $stock($r), OrderStore::find($mine->getId())->meta(OrderStock::TAKEN)]);
pin('it is logged for a refund, never as oversold', [1, 0], [$logged('paid after close', $mine->getId()), $logged('oversold', $mine->getId())]);
$callback($kims);
pin('a paid order is left alone when its hold ends', [false, 'paid', '0'], [Billing::expireCardHold($kims->getId()), OrderStore::find($kims->getId())->getStatus(), $stock($r)]);
osc_remove_hook('billing_order_cancelled', $onCancel);
$cardHold = static function (string $minutes): int {
    Preference::getInstance()->set('billing_card_hold_minutes', $minutes);
    scratchdb_forget_cache();
    osc_reset_preferences();

    return osc_billing_card_hold_minutes();
};
pin('the hold is 5 to 120 minutes, 15 when unset', [5, 120, 30, 15], [$cardHold('1'), $cardHold('500'), $cardHold('30'), $cardHold('')]);
$cardHold('30');
$setStock($r, 1);
$thirty = $placeAs($tom, 'card');
pin('the job waits the set minutes', true, abs((int) ($holdJob($thirty)['i_in'] ?? 0) - 1800) <= 5);
OrderStore::cancel($thirty->getId());
$cardHold('');
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
pin('the orders took all 5', '0', $stock($a));
$cancelled = [];
$onCancel  = static function ($orderId) use (&$cancelled): void {
    $cancelled[] = (int) $orderId;
};
osc_add_hook('billing_order_cancelled', $onCancel);
pin('one run cancels the two held orders and the left card order past the 7-day hold', 3, Billing::releaseHeld());
osc_remove_hook('billing_order_cancelled', $onCancel);
$status = static fn (Order $o): string => OrderStore::find($o->getId())->getStatus();
pin('only those', ['cancelled', 'cancelled', 'pending', 'cancelled', 'paid', 'pending'], array_map($status, [$oldBank, $oldCod, $newBank, $oldCard, $oldPaid, $oldCredit]));
pin('each announced once', [$oldBank->getId(), $oldCod->getId(), $oldCard->getId()], $cancelled);
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

harness_section('one gateway\'s pending orders, for its provider check');
$poll = [];
foreach ([5, 3, 1] as $days) {
    $o = OrderStore::create($tom, 'poll', $m(5), 'USD', $days === 3 ? 10 : 0);
    OrderStore::attachRef($o->getId(), 'poll', 'cs_' . $days);
    $age($o, $days);
    $poll[$days] = $o->getId();
}
$noRef = OrderStore::create($tom, 'poll', $m(5), 'USD', 0);
$age($noRef, 4);
$otherRef = OrderStore::create($tom, 'poll', $m(5), 'USD', 0);
OrderStore::attachRef($otherRef->getId(), 'poll', 'pi_x');
$age($otherRef, 4);
$fresh = OrderStore::create($tom, 'poll', $m(5), 'USD', 0);
OrderStore::attachRef($fresh->getId(), 'poll', 'cs_fresh');
$elsewhere = OrderStore::create($tom, 'card', $m(5), 'USD', 0);
OrderStore::attachRef($elsewhere->getId(), 'card', 'cs_card');
$age($elsewhere, 6);
$ids = static fn (array $orders): array => array_map(static fn (Order $o): int => $o->getId(), $orders);
$dayAgo = date('Y-m-d H:i:s', time() - 43200);
pin('oldest first, goods and credit orders, only ours with the prefix', [$poll[5], $poll[3], $poll[1]], $ids(OrderStore::pendingBefore('poll', $dayAgo, 10, 'cs_')));
pin('the limit keeps the oldest', [$poll[5]], $ids(OrderStore::pendingBefore('poll', $dayAgo, 1, 'cs_')));
pin('no prefix: every order with a reference', [$poll[5], $otherRef->getId(), $poll[3], $poll[1]], $ids(OrderStore::pendingBefore('poll', $dayAgo, 10)));
Billing::markPaid(OrderStore::find($poll[5]));
pin('a settled order drops out', [$poll[3], $poll[1]], $ids(OrderStore::pendingBefore('poll', $dayAgo, 10, 'cs_')));
pin('a LIKE wildcard in the prefix is literal', [], $ids(OrderStore::pendingBefore('poll', $dayAgo, 10, 'c%')));

harness_section('open held orders per buyer');
$setLimit = static function (string $limit): void {
    Preference::getInstance()->set('billing_open_hold_orders', $limit);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$setStock($a, 50);
$placeKim = static fn (string $gateway) => $checkout->place($kim, [['variant' => $a, 'qty' => 1]], $address, 'standard', $gateway)['order'];
$setLimit('3');
$kimOrders = [$placeKim('offline'), $placeKim('cod'), $placeKim('offline')];
pin('the buyer holds three open orders', 3, OrderStore::countForUser($kim, [Order::STATUS_PENDING], Billing::HOLDS_STOCK));
pin('a fourth cash on delivery order is refused on the gateway', ['/gateway too_many_open'], $refused(static fn () => $placeKim('cod')));
pin('and a fourth bank transfer order, so switching gateways does not get round it', ['/gateway too_many_open'], $refused(static fn () => $placeKim('offline')));
pin('the refused orders wrote nothing', 3, OrderStore::countForUser($kim, [Order::STATUS_PENDING]));
pin('and a fourth card order, which takes stock too', ['/gateway too_many_open'], $refused(static fn () => $placeKim('card')));
$lee = seed_user($admin, 'lee', 'lee@example.test');
pin('another buyer is not affected', true, $checkout->place($lee, [['variant' => $a, 'qty' => 1]], $address, 'standard', 'offline')['order']->getId() > 0);
OrderStore::cancel($kimOrders[0]->getId());
$again = $placeKim('offline');
pin('cancelling one frees a slot', true, $again->getId() > 0);
pin('at the limit again', ['/gateway too_many_open'], $refused(static fn () => $placeKim('offline')));
OrderStore::cancel($kimOrders[1]->getId());
$cardOrder = $placeKim('card');
pin('an open card order takes a slot', ['card', ['/gateway too_many_open']], [$cardOrder->getGateway(), $refused(static fn () => $placeKim('offline'))]);
Billing::markPaid(OrderStore::find($kimOrders[2]->getId()));
$afterPaid = $placeKim('offline');
pin('a paid offline order does not count toward the limit', [3, true], [OrderStore::countForUser($kim, [Order::STATUS_PENDING]), $afterPaid->getId() > 0]);
$setLimit('0');
$more = [$placeKim('offline'), $placeKim('cod')];
pin('a limit of 0 never refuses', true, $more[1]->getId() > 0);
$setLimit('3');
pin('and the limit applies again once set', ['/gateway too_many_open'], $refused(static fn () => $placeKim('cod')));
$setLimit('0');

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

harness_section('a cart of downloads');
Preference::getInstance()->set('billing_shipping_flat', (string) $m(3));
scratchdb_forget_cache();
osc_reset_preferences();
$download = static fn ($needs, $cart) => false;
osc_add_filter('cart_needs_shipping', $download);
$q = $pricer->quote([['variant' => $u, 'qty' => 1]], ['gateway' => 'cod']);
pin('needs no shipping: no methods, no delivery charge, no meter, no cash fee', [false, [], [], null, $m(5)], [$q->needsShipping(), $q->shippingMethods(), $q->extraLines('shipping'), $q->freeOver(), $q->total()]);
pin('cash on delivery is not offered', [false, true], [isset(GoodsCheckout::gateways($q)['cod']), isset(GoodsCheckout::gateways($q)['offline'])]);
pin('placing with cash on delivery is refused', ['/gateway unavailable'], $refused(static fn () => $checkout->place($tom, [['variant' => $u, 'qty' => 1]], [], '', 'cod')));
$dl = $checkout->place($tom, [['variant' => $u, 'qty' => 1]], [], 'standard', 'offline')['order'];
pin('placed with no address and no delivery', [$m(5), null, null, ['item']], [$dl->getAmount(), $dl->meta('_address'), $dl->fulfilment()['method'], array_column(OrderLineStore::ofOrder($dl->getId()), 'kind')]);
OrderStore::cancel($dl->getId());
$dl = $checkout->place($tom, [['variant' => $u, 'qty' => 1]], ['country' => 'DE', 'state' => 'Berlin', 'line1' => '1 Main St', 'city' => 'Berlin'], '', 'offline')['order'];
pin('a downloads order keeps the buyer\'s country and state, and no street', ['DE', 'Berlin', ['state' => 'Berlin', 'country' => 'DE']], [$dl->meta('_address')['country'], $dl->meta('_address')['state'], $dl->meta('_address')]);
OrderStore::cancel($dl->getId());
osc_remove_filter('cart_needs_shipping', $download);
$q = $pricer->quote([['variant' => $u, 'qty' => 1]], ['gateway' => 'cod']);
pin('a parcel still needs the address and pays delivery', [true, $m(3), ['/address/name required']], [$q->needsShipping(), $q->totals()['shipping'], array_slice($refused(static fn () => $checkout->place($tom, [['variant' => $u, 'qty' => 1]], [], 'standard', 'offline')), 0, 1)]);
Preference::getInstance()->set('billing_shipping_flat', '0');
scratchdb_forget_cache();
osc_reset_preferences();
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
