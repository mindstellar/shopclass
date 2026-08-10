<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins for the billing layer: the credit wallet and its ledger, order settlement, and
 * the premium-upgrade sweep.
 *
 * The properties under test here are the ones whose absence is not visible in normal
 * use and only shows up as missing or invented money:
 *
 *   - a replayed webhook credits once, not twice
 *   - two concurrent spends of the last credit cannot both succeed
 *   - a callback claiming the wrong amount settles nothing
 *   - one gateway cannot settle another's orders
 *   - an order marked paid always has its credits, and never has them twice
 *
 * Usage:  php tests/models/billing.php          (standalone, own scratch database)
 *         php tests/run-models.php billing      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_billing');

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\Orders;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Premium;
use mindstellar\billing\Wallet;

/**
 * A gateway whose verdict the test dictates. Stands in for a real provider so the
 * verification core performs on a callback can be exercised without one.
 */
final class FakeGateway implements PaymentGateway
{
    public ?CallbackResult $verdict = null;

    public function __construct(private string $id = 'fake', private bool $configured = true)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return 'Fake';
    }

    public function getSupportedCurrencies(): array
    {
        return array('USD', 'EUR');
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        return CheckoutIntent::redirect('https://example.test/pay/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return $this->verdict ?? CallbackResult::ignored();
    }
}

$userId  = seed_user($admin, 'buyer', 'buyer@example.test');
$otherId = seed_user($admin, 'other', 'other@example.test');

$ledgerCount = static function (int $uid) use ($admin): int {
    $res = $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_billing_ledger WHERE fk_i_user_id = ' . $uid
    );

    return (int) $res->fetch_assoc()['c'];
};

/* ----------------------------------------------------------------------------
 * Wallet basics.
 * ------------------------------------------------------------------------- */
harness_section('Wallet: balance and ledger');

pin('unknown user reads as zero', 0, Wallet::balance($userId));

check(
    'reading a balance creates no wallet row',
    (int) $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_billing_wallet')
        ->fetch_assoc()['c'] === 0
);

Wallet::credit($userId, 100, Wallet::REASON_GRANT);
pin('credit moves the balance', 100, Wallet::balance($userId));
pin('credit writes one ledger row', 1, $ledgerCount($userId));

$row = $admin->query(
    'SELECT * FROM ' . DB_TABLE_PREFIX . 't_billing_ledger WHERE fk_i_user_id = ' . $userId
)->fetch_assoc();
pin('ledger records the delta', '100', $row['i_amount']);
pin('ledger records the resulting balance', '100', $row['i_balance_after']);
pin('ledger records the reason', 'grant', $row['s_reason']);

Wallet::credit($userId, 50, Wallet::REASON_PURCHASE);
pin('balances accumulate', 150, Wallet::balance($userId));
pin('balance_after tracks the running total', '150', $admin->query(
    'SELECT i_balance_after FROM ' . DB_TABLE_PREFIX . 't_billing_ledger'
    . ' WHERE fk_i_user_id = ' . $userId . ' ORDER BY pk_i_id DESC LIMIT 1'
)->fetch_assoc()['i_balance_after']);

/* ----------------------------------------------------------------------------
 * Idempotency. The property that keeps a retried webhook from minting twice.
 * ------------------------------------------------------------------------- */
harness_section('Wallet: idempotency');

$before = Wallet::balance($userId);
Wallet::credit($userId, 25, Wallet::REASON_PURCHASE, 'evt_abc');
$afterFirst = Wallet::balance($userId);
$replay = Wallet::credit($userId, 25, Wallet::REASON_PURCHASE, 'evt_abc');

pin('first keyed credit applies', $before + 25, $afterFirst);
pin('replayed credit does not apply again', $afterFirst, Wallet::balance($userId));
check('replay still reports success', $replay === true);
pin('replay writes no second ledger row', 1, (int) $admin->query(
    'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_billing_ledger'
    . " WHERE s_idempotency_key = 'evt_abc'"
)->fetch_assoc()['c']);

/* ----------------------------------------------------------------------------
 * Debit and the overdraw guard.
 * ------------------------------------------------------------------------- */
harness_section('Wallet: debit');

$balance = Wallet::balance($userId);
check('debit within balance succeeds', Wallet::debit($userId, 75) === true);
pin('debit deducts', $balance - 75, Wallet::balance($userId));
pin('debit records a negative delta', '-75', $admin->query(
    'SELECT i_amount FROM ' . DB_TABLE_PREFIX . 't_billing_ledger'
    . ' WHERE fk_i_user_id = ' . $userId . ' ORDER BY pk_i_id DESC LIMIT 1'
)->fetch_assoc()['i_amount']);

$balance = Wallet::balance($userId);
$rows    = $ledgerCount($userId);
check('debit beyond balance is refused', Wallet::debit($userId, $balance + 1) === false);
pin('refused debit leaves the balance alone', $balance, Wallet::balance($userId));
pin('refused debit writes no ledger row', $rows, $ledgerCount($userId));

check(
    'debit of the exact balance is allowed',
    Wallet::debit($userId, Wallet::balance($userId)) === true
);
pin('spending everything lands on zero', 0, Wallet::balance($userId));

/* A reversal is the one path allowed to overdraw: by the time a provider claws a
 * payment back the user has usually spent what it bought, and refusing would mean
 * the site simply gave the goods away. */
check('reverse may drive the balance negative', Wallet::reverse($userId, 30) === true);
pin('reversal leaves an honest negative balance', -30, Wallet::balance($userId));
check('a negative balance blocks further spending', Wallet::debit($userId, 1) === false);

Wallet::credit($userId, 30, Wallet::REASON_GRANT); // back to zero for later sections

/* ----------------------------------------------------------------------------
 * Orders.
 * ------------------------------------------------------------------------- */
harness_section('Orders: lifecycle');

$order = Orders::create($userId, 'fake', 9_990_000, 'USD', 100, array('sku' => 'credits-100'));
pin('new orders start pending', Order::STATUS_PENDING, $order->getStatus());
pin('amount is stored in micros', 9990000, $order->getAmount());
pin('metadata round-trips', 'credits-100', Orders::find($order->getId())->meta('sku'));

check('settling a pending order succeeds', Orders::settle($order->getId(), Order::STATUS_PAID, 'ext_1'));
check('re-settling the same order is refused', Orders::settle($order->getId(), Order::STATUS_PAID, 'ext_1') === false);
pin('settled order reads back as paid', Order::STATUS_PAID, Orders::find($order->getId())->getStatus());
pin('external reference is stored', 'ext_1', Orders::find($order->getId())->getExternalRef());
check('paid orders carry a paid date', Orders::find($order->getId())->getPaidDate() !== null);

pin(
    'lookup by gateway reference finds the order',
    $order->getId(),
    Orders::findByGatewayRef('fake', 'ext_1')->getId()
);
check(
    'lookup is scoped to the gateway',
    Orders::findByGatewayRef('other', 'ext_1') === null
);

/* ----------------------------------------------------------------------------
 * Fulfilment. Settling and crediting have to be one atomic step.
 * ------------------------------------------------------------------------- */
harness_section('Billing: markPaid');

$balance = Wallet::balance($userId);
$order   = Orders::create($userId, 'fake', 1_000_000, 'USD', 40);

check('markPaid settles the order', Billing::markPaid($order, 'ext_2') === true);
pin('markPaid mints the credits', $balance + 40, Wallet::balance($userId));
pin('order is paid', Order::STATUS_PAID, Orders::find($order->getId())->getStatus());

check('markPaid on an already-paid order reports no change', Billing::markPaid($order, 'ext_2') === false);
pin('a repeated markPaid mints nothing further', $balance + 40, Wallet::balance($userId));

/* ----------------------------------------------------------------------------
 * Callback verification. A callback is attacker-reachable input.
 * ------------------------------------------------------------------------- */
harness_section('Billing: callback verification');

$gateway = new FakeGateway('fake');
PaymentGatewayRegistry::instance()->register($gateway);

$balance = Wallet::balance($userId);
$order   = Orders::create($userId, 'fake', 5_000_000, 'USD', 200);

$gateway->verdict = CallbackResult::paid($order->getId(), 'ext_3', 1, 'USD');
$result = Billing::handleCallback('fake', array());
pin('a short-paid callback is ignored', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('a short-paid callback mints nothing', $balance, Wallet::balance($userId));
pin('a short-paid order stays pending', Order::STATUS_PENDING, Orders::find($order->getId())->getStatus());

$gateway->verdict = CallbackResult::paid($order->getId(), 'ext_3', 5_000_000, 'EUR');
$result = Billing::handleCallback('fake', array());
pin('a wrong-currency callback is ignored', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('a wrong-currency callback mints nothing', $balance, Wallet::balance($userId));

$gateway->verdict = CallbackResult::paid($order->getId(), 'ext_3', 5_000_000, 'USD');
$result = Billing::handleCallback('fake', array());
pin('a matching callback settles', CallbackResult::OUTCOME_PAID, $result->getOutcome());
pin('a matching callback mints the credits', $balance + 200, Wallet::balance($userId));

/* The same event arriving twice is the normal case, not the exceptional one. */
$result = Billing::handleCallback('fake', array());
pin('replaying the callback mints nothing further', $balance + 200, Wallet::balance($userId));

/* A gateway must not be able to settle an order that belongs to another. */
$rival = new FakeGateway('rival');
PaymentGatewayRegistry::instance()->register($rival);

$balance     = Wallet::balance($otherId);
$rivalTarget = Orders::create($otherId, 'fake', 2_000_000, 'USD', 500);
$rival->verdict = CallbackResult::paid($rivalTarget->getId(), 'ext_4', 2_000_000, 'USD');

$result = Billing::handleCallback('rival', array());
pin('one gateway cannot settle another\'s order', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('the cross-gateway attempt mints nothing', $balance, Wallet::balance($otherId));
pin(
    'the targeted order stays pending',
    Order::STATUS_PENDING,
    Orders::find($rivalTarget->getId())->getStatus()
);

pin(
    'an unknown gateway is ignored',
    CallbackResult::OUTCOME_IGNORED,
    Billing::handleCallback('nope', array())->getOutcome()
);

/* ----------------------------------------------------------------------------
 * Refunds.
 * ------------------------------------------------------------------------- */
harness_section('Billing: refund');

$balance = Wallet::balance($userId);
$order   = Orders::create($userId, 'fake', 1_000_000, 'USD', 60);
Billing::markPaid($order, 'ext_5');
pin('paid order credits', $balance + 60, Wallet::balance($userId));

check('refund reverses a paid order', Billing::refund(Orders::find($order->getId())) === true);
pin('refund takes the credits back', $balance, Wallet::balance($userId));
pin('refunded order reads back as refunded', Order::STATUS_REFUNDED, Orders::find($order->getId())->getStatus());
check(
    'refunding twice reports no change',
    Billing::refund(Orders::find($order->getId())) === false
);
pin('a repeated refund takes nothing further', $balance, Wallet::balance($userId));

/* ----------------------------------------------------------------------------
 * Registry.
 * ------------------------------------------------------------------------- */
harness_section('PaymentGatewayRegistry');

pin('registered gateway is retrievable', 'fake', PaymentGatewayRegistry::instance()->get('fake')->getId());
pin('unknown gateway reads as null', null, PaymentGatewayRegistry::instance()->get('missing'));

$unconfigured = new FakeGateway('halfdone', false);
PaymentGatewayRegistry::instance()->register($unconfigured);
check(
    'an unconfigured gateway is listed but not offered',
    isset(PaymentGatewayRegistry::instance()->all()['halfdone'])
    && !isset(PaymentGatewayRegistry::instance()->available()['halfdone'])
);
check(
    'available() filters by currency',
    isset(PaymentGatewayRegistry::instance()->available('USD')['fake'])
    && !isset(PaymentGatewayRegistry::instance()->available('GBP')['fake'])
);

check('valid ids are lower-case slugs', PaymentGatewayRegistry::isValidId('stripe-eu.v2'));
check('ids reject upper case', !PaymentGatewayRegistry::isValidId('Stripe'));
check('ids reject spaces', !PaymentGatewayRegistry::isValidId('my gateway'));

$threw = false;
try {
    PaymentGatewayRegistry::instance()->register(new FakeGateway('Bad Id'));
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('registering an invalid id throws', $threw);

/* ----------------------------------------------------------------------------
 * Premium expiry. Without the sweep a time-limited upgrade never ends, because
 * b_premium = 1 exempts a listing from dt_expiration everywhere else.
 * ------------------------------------------------------------------------- */
harness_section('Premium: expiry sweep');

seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$categoryId = seed_category($admin);

$expired   = seed_item($admin, $categoryId, $userId, 'Expired premium');
$future    = seed_item($admin, $categoryId, $userId, 'Still premium');
$permanent = seed_item($admin, $categoryId, $userId, 'Permanent premium');
$plain     = seed_item($admin, $categoryId, $userId, 'Never premium');

$setPremium = static function (int $id, ?string $expires) use ($admin): void {
    $admin->query(
        'UPDATE ' . DB_TABLE_PREFIX . 't_item SET b_premium = 1, dt_premium_expiration = '
        . ($expires === null ? 'NULL' : "'" . $expires . "'") . ' WHERE pk_i_id = ' . $id
    );
};

$setPremium($expired, date('Y-m-d H:i:s', time() - 3600));
$setPremium($future, date('Y-m-d H:i:s', time() + 86400));
$setPremium($permanent, null);

pin('sweep ends exactly the lapsed upgrades', 1, Premium::expire());

$isPremium = static function (int $id) use ($admin): string {
    return $admin->query(
        'SELECT b_premium FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $id
    )->fetch_assoc()['b_premium'];
};

pin('the lapsed upgrade is off', '0', $isPremium($expired));
pin('a future-dated upgrade is untouched', '1', $isPremium($future));
pin('a permanent upgrade is untouched', '1', $isPremium($permanent));
pin('a non-premium listing is untouched', '0', $isPremium($plain));

pin('the swept row has its date cleared', null, $admin->query(
    'SELECT dt_premium_expiration FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $expired
)->fetch_assoc()['dt_premium_expiration']);

pin('a second sweep finds nothing', 0, Premium::expire());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
