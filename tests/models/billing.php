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
 * Pins for the billing layer: the credit wallet and its ledger, order settlement, the
 * premium-upgrade sweep, entitlements, feature spending, and the package catalogue.
 *
 * The properties under test here are the ones whose absence is not visible in normal
 * use and only shows up as missing or invented money:
 *
 *   - a replayed webhook credits once, not twice
 *   - two concurrent spends of the last credit cannot both succeed
 *   - a callback claiming the wrong amount settles nothing
 *   - one gateway cannot settle another's orders
 *   - an order marked paid always has its credits, and never has them twice
 *   - a quantity entitlement grant adds to what is already held, not a second row
 *   - a duration grant compounds onto the time remaining, not onto now
 *   - the last unit of a quantity entitlement cannot be spent twice
 *   - an expired entitlement spends nothing
 *   - money and the entitlement it buys move together, or neither moves at all
 *   - a package's price is what reaches the order, never a number the browser supplied
 *
 * Usage:  php tests/models/billing.php          (standalone, own scratch database)
 *         php tests/run-models.php billing      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_billing');

// hBilling.php registers the built-in features and a couple of hooks the moment it is
// included, and Plugins::addHook() resolves the caller against PLUGINS_PATH -- the
// same stand-in tests/models/item.php uses, since hDefines.php pulls in far more than
// this file needs.
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
// hBilling.php also registers the wallet/buy/orders render targets, which needs
// osc_register_render_target() from hTheme.php -- a no-op stand-in keeps that
// registration harmless without pulling in hTheme.php.
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
// Entitlements::withinFreeQuota()/canPublish() read osc_billing_free_posts_per_period()
// and friends, which live here rather than in the default bootstrap requires.
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hBilling.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Entitlements;
use mindstellar\billing\Feature;
use mindstellar\billing\FeatureRegistry;
use mindstellar\billing\ItemUpgrades;
use mindstellar\billing\Order;
use mindstellar\billing\Orders;
use mindstellar\billing\Packages;
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
/* A mismatch core looked at and decided on is not the same as core never getting
 * to look at all -- only the latter should make a provider retry. */
check('a cross-gateway mismatch is a decision core made, not retryable', $result->isRetryable() === false);

/* ----------------------------------------------------------------------------
 * A callback core could not process at all -- no gateway registered, which is
 * what happens while billing is switched off -- must be retryable, or a real
 * payment arriving during that window is acknowledged and never retried.
 * ------------------------------------------------------------------------- */
$unknownGatewayResult = Billing::handleCallback('nope', array());
pin('an unknown gateway is ignored', CallbackResult::OUTCOME_IGNORED, $unknownGatewayResult->getOutcome());
check(
    'an unknown gateway is retryable -- core never resolved the callback at all',
    $unknownGatewayResult->isRetryable() === true
);

/* Every outcome CallbackResult can carry other than an unresolvable ignore must
 * default to not-retryable -- paid/failed/refunded and a deliberate ignore all
 * answer 200 on replay, or a provider would retry forever. */
check('paid() is never retryable', CallbackResult::paid(1)->isRetryable() === false);
check('failed() is never retryable', CallbackResult::failed(1)->isRetryable() === false);
check('refunded() is never retryable', CallbackResult::refunded(1)->isRetryable() === false);
check('ignored() defaults to not retryable', CallbackResult::ignored('anything')->isRetryable() === false);
check(
    'ignored() is retryable only when explicitly told so',
    CallbackResult::ignored('unresolvable', true)->isRetryable() === true
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
 * Reopening a failed order. Orders::settle()/Billing::markPaid() are guarded on
 * pending by default -- a gateway retrying its own callback must never revive a
 * failed order by itself. The admin "mark paid" escape hatch is the one caller
 * allowed to widen that guard, because a person is confirming a real retried
 * payment, not a replayed webhook doing it unattended.
 * ------------------------------------------------------------------------- */
harness_section('Orders/Billing: a failed order can be reopened, but only by the admin path');

$failedOrder = Orders::create($userId, 'fake', 1_000_000, 'USD', 15);
check('an order can be moved to failed', Orders::settle($failedOrder->getId(), Order::STATUS_FAILED, 'ext_6'));
pin('the order reads back as failed', Order::STATUS_FAILED, Orders::find($failedOrder->getId())->getStatus());

$balanceBeforeReopen = Wallet::balance($userId);
check(
    'the default (gateway-callback) guard refuses to settle a failed order',
    Billing::markPaid(Orders::find($failedOrder->getId()), 'ext_6') === false
);
pin('a refused reopen mints nothing', $balanceBeforeReopen, Wallet::balance($userId));
pin(
    'a refused reopen leaves the order failed',
    Order::STATUS_FAILED,
    Orders::find($failedOrder->getId())->getStatus()
);

check(
    'the admin-only path (allowFailed) settles a failed order',
    Billing::markPaid(Orders::find($failedOrder->getId()), 'ext_6', true) === true
);
pin('reopening a failed order mints its credits', $balanceBeforeReopen + 15, Wallet::balance($userId));
pin('the reopened order reads back as paid', Order::STATUS_PAID, Orders::find($failedOrder->getId())->getStatus());

check(
    'reopening an order that is already paid reports no change even with allowFailed',
    Billing::markPaid(Orders::find($failedOrder->getId()), 'ext_6', true) === false
);
pin('a repeated reopen mints nothing further', $balanceBeforeReopen + 15, Wallet::balance($userId));

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

/* ----------------------------------------------------------------------------
 * listing.premium: the enabled/credits split. Registered only when
 * billing_premium_enabled is on, the same rule every other purchasable feature
 * in this file follows -- an enabled feature priced at 0 credits is free to
 * every seller, not switched off. This is the property osc_item_can_be_featured()
 * and CWebBilling::upgradePost() both had to stop reading billing_premium_credits()
 * for.
 * ------------------------------------------------------------------------- */
harness_section('Billing: listing.premium enabled/credits split');

// hBilling.php's own require (above) is the only place in this process that ever
// runs the load-time registration, against a freshly-truncated t_preference table
// where billing_premium_enabled reads unset -- i.e. off, the same default a fresh
// install ships with. So this is the pristine "disabled" state, not one manufactured
// by this test.
$premiumUserId = seed_user($admin, 'premium', 'premium@example.test');
$premiumItemId = seed_item($admin, $categoryId, $premiumUserId, 'Premium target');

check('listing.premium is unregistered while disabled', FeatureRegistry::instance()->get('listing.premium') === null);
check(
    'spending on listing.premium while disabled fails',
    Billing::spend($premiumUserId, 'listing.premium', array('itemId' => $premiumItemId, 'ref_type' => 'item', 'ref_id' => $premiumItemId)) === false
);

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_premium_credits', '25', 'osclass', 'INTEGER');
osc_reset_preferences();
check(
    'osc_item_can_be_featured() follows the enabled flag, not a price sitting unused',
    osc_item_can_be_featured(array('pk_i_id' => $premiumItemId, 'b_premium' => 0)) === false
);

// Flipping the preference here needs the same registration re-run the admin
// Pricing save triggers -- hBilling.php only re-evaluates the gate when asked.
osc_set_preference('billing_premium_enabled', '1', 'osclass', 'BOOLEAN');
osc_set_preference('billing_premium_credits', '0', 'osclass', 'INTEGER');
osc_set_preference('billing_premium_days', '30', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_premium();

check('listing.premium registers once its preference is on', FeatureRegistry::instance()->get('listing.premium') !== null);
check(
    'osc_item_can_be_featured() is true once enabled, even at 0 credits',
    osc_item_can_be_featured(array('pk_i_id' => $premiumItemId, 'b_premium' => 0)) === true
);

$balanceBeforePremium = Wallet::balance($premiumUserId);
check(
    'spending on a free (0-credit) but enabled listing.premium succeeds',
    Billing::spend($premiumUserId, 'listing.premium', array('itemId' => $premiumItemId, 'ref_type' => 'item', 'ref_id' => $premiumItemId))
);
pin('a free premium spend debits nothing', $balanceBeforePremium, Wallet::balance($premiumUserId));
pin('the free spend still marks the item premium', '1', $admin->query(
    'SELECT b_premium FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $premiumItemId
)->fetch_assoc()['b_premium']);
check(
    'osc_item_can_be_featured() is false once the item already holds it',
    osc_item_can_be_featured(array('pk_i_id' => $premiumItemId, 'b_premium' => 1)) === false
);

// Billing off is the master switch: a feature left enabled and priced at 0 must not
// become a free upgrade for anyone who reaches spend() directly. The public route
// redirects, but a plugin calling spend() has to meet the same refusal.
$offItemId = seed_item($admin, $categoryId, $premiumUserId, 'Premium while billing is off');
osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
check(
    'a free feature does not apply while billing is switched off',
    Billing::spend($premiumUserId, 'listing.premium', array('itemId' => $offItemId)) === false
);
pin('the item is untouched by a spend made while billing is off', '0', $admin->query(
    'SELECT b_premium FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $offItemId
)->fetch_assoc()['b_premium']);

osc_set_preference('billing_premium_enabled', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * Entitlements: granting. grant() merges into the unexpired row for a feature
 * rather than appending, so quantity() and quantity() must show a single
 * combined figure and the table must carry exactly one row for it.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: grant merges rather than accumulates');

$entCount = static function (int $uid, string $feature) use ($admin): int {
    $res = $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_user_entitlement'
        . ' WHERE fk_i_user_id = ' . $uid . " AND s_feature = '" . $feature . "'"
    );

    return (int) $res->fetch_assoc()['c'];
};
$expirationOf = static function (int $uid, string $feature) use ($admin): ?string {
    $res = $admin->query(
        'SELECT dt_expiration FROM ' . DB_TABLE_PREFIX . 't_user_entitlement'
        . ' WHERE fk_i_user_id = ' . $uid . " AND s_feature = '" . $feature . "'"
        . ' ORDER BY pk_i_id DESC LIMIT 1'
    );

    return $res->fetch_assoc()['dt_expiration'] ?? null;
};

$entUserId = seed_user($admin, 'entitled', 'entitled@example.test');

check('a fresh quantity grant creates a row', Entitlements::grant($entUserId, 'test.qty', 5, null));
pin('the fresh grant reads back as its own quantity', 5, Entitlements::quantity($entUserId, 'test.qty'));

check('granting the same feature again succeeds', Entitlements::grant($entUserId, 'test.qty', 3, null));
pin('a quantity grant merges into the existing row', 8, Entitlements::quantity($entUserId, 'test.qty'));
pin(
    'the merge writes exactly one row, not a second -- the unique key on (user, feature) enforces it',
    1,
    $entCount($entUserId, 'test.qty')
);

/* Unlimited (i_quantity IS NULL) has to absorb a quantity grant and stay
 * unlimited -- a buyer already holding "unlimited" must never be knocked down to
 * a finite number by topping up. */
$unlimitedGrantUserId = seed_user($admin, 'unlimitedgrant', 'unlimitedgrant@example.test');
$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_user_entitlement'
    . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
    . ' VALUES (' . $unlimitedGrantUserId . ", 'test.qty.unlimited', NULL, NULL, 'grant', NOW())"
);
check(
    'a quantity grant onto an unlimited row still reports success',
    Entitlements::grant($unlimitedGrantUserId, 'test.qty.unlimited', 5, null)
);
pin(
    'a quantity grant onto an unlimited row leaves it unlimited',
    -1,
    Entitlements::quantity($unlimitedGrantUserId, 'test.qty.unlimited')
);
pin('the grant onto the unlimited row still writes exactly one row', 1, $entCount($unlimitedGrantUserId, 'test.qty.unlimited'));

/* A duration grant while time remains has to compound onto that remaining time,
 * not discard it -- otherwise buying 30 more days while 10 remain would be a
 * downgrade to 30 instead of the 40 the buyer paid for. */
check('a fresh duration grant creates a row', Entitlements::grant($entUserId, 'test.dur', null, 10));
$firstExpiration = $expirationOf($entUserId, 'test.dur');
check('the fresh grant has an expiration', $firstExpiration !== null);

check('extending the same feature again succeeds', Entitlements::grant($entUserId, 'test.dur', null, 5));
pin(
    'a duration grant extends from the current expiry, not from now',
    date('Y-m-d H:i:s', strtotime($firstExpiration) + 5 * 86400),
    $expirationOf($entUserId, 'test.dur')
);
pin('the duration extension still writes exactly one row, not a second', 1, $entCount($entUserId, 'test.dur'));

/* ----------------------------------------------------------------------------
 * Calendar-correct day arithmetic. Entitlements::addDays() and
 * ItemUpgrades::nextExpiration() must add calendar days, not days * 86400 raw
 * seconds, or a purchase made near a DST boundary expires an hour early or
 * late. Set explicitly rather than trusting the host's zone, which may not
 * observe DST at all -- the property below only shows up in one that does.
 * ------------------------------------------------------------------------- */
harness_section('Calendar-correct day arithmetic across a DST boundary');

$previousTz = date_default_timezone_get();
date_default_timezone_set('America/New_York');

// 2024-03-10 is the US spring-forward transition: 02:00 EST becomes 03:00 EDT.
// A base 30 calendar days before it, at 10:00 local, must still read 10:00
// local 30 days later. days * 86400 instead lands on 11:00 -- the 30*86400
// raw seconds span one hour less of wall-clock offset than 30 calendar days
// actually cover once the clocks spring forward partway through.
$addDays = new ReflectionMethod(Entitlements::class, 'addDays');
$addDays->setAccessible(true);
pin(
    'Entitlements::addDays() preserves wall-clock time across a DST boundary',
    '2024-03-10 10:00:00',
    $addDays->invoke(null, '2024-02-09 10:00:00', 30)
);

$nextExpiration = new ReflectionMethod(ItemUpgrades::class, 'nextExpiration');
$nextExpiration->setAccessible(true);
pin(
    'ItemUpgrades::nextExpiration() days offset preserves wall-clock time across the same boundary',
    '2024-03-10 10:00:00',
    $nextExpiration->invoke(null, null, 30, null, '2024-02-09 10:00:00')
);

// Hours are the deliberate exception: a 24-hour bump cooldown means 24 real
// elapsed hours, not "this time tomorrow", so it must NOT track the wall clock
// across the same boundary -- 24 hours after 2024-03-09 10:00 is 11:00 local
// the next day, once the clocks have sprung forward in between.
pin(
    'ItemUpgrades::nextExpiration() hours offset stays literal elapsed seconds across the same boundary',
    '2024-03-10 11:00:00',
    $nextExpiration->invoke(null, null, null, 24, '2024-03-09 10:00:00')
);

date_default_timezone_set($previousTz);

/* ----------------------------------------------------------------------------
 * Entitlements: consuming. consume() is a single conditional UPDATE -- the
 * quantity has to reach exactly zero and no further, and an expired row must
 * not be spendable even though its quantity is still positive.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: consume');

Entitlements::grant($entUserId, 'test.single', 1, null);
check('consuming the only unit succeeds', Entitlements::consume($entUserId, 'test.single', 1) === true);
check('consuming again with nothing left fails', Entitlements::consume($entUserId, 'test.single', 1) === false);
pin('the exhausted entitlement reads back as zero', 0, Entitlements::quantity($entUserId, 'test.single'));

$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_user_entitlement'
    . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
    . ' VALUES (' . $entUserId . ", 'test.expired', 5, '"
    . date('Y-m-d H:i:s', time() - 3600) . "', 'grant', NOW())"
);
check(
    'consuming an expired entitlement fails despite a positive quantity',
    Entitlements::consume($entUserId, 'test.expired', 1) === false
);

/* ----------------------------------------------------------------------------
 * Entitlements: the publish choke point. canPublish() has to track three
 * states in order -- inside the free quota, over quota with nothing bought,
 * and over quota with an entitlement in hand -- because ItemActions::add()
 * trusts this single answer.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: canPublish');

$quotaUserId = seed_user($admin, 'quotauser', 'quotauser@example.test');
osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_posts_per_period', '1', 'osclass', 'INTEGER');

check('under the free quota, publishing is allowed', Entitlements::canPublish($quotaUserId));

seed_item($admin, $categoryId, $quotaUserId, 'Uses up the free quota');
check(
    'once the free quota is used and nothing was bought, publishing is refused',
    Entitlements::canPublish($quotaUserId) === false
);

Entitlements::grant($quotaUserId, 'listing.publish', 1, null);
check(
    'an entitlement restores publishing once the free quota is spent',
    Entitlements::canPublish($quotaUserId) === true
);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');

/* ----------------------------------------------------------------------------
 * Entitlements: publishQuotaUsed() counts publication events, not the sort key.
 * item.bump's own apply() moves dt_pub_date on purpose, to resort the listing --
 * it must never also refill the free quota dt_first_pub_date exists to protect.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: publishQuotaUsed() counts publish events, not bumps');

$firstPubUserId = seed_user($admin, 'firstpub', 'firstpub@example.test');
$firstPubItemId = seed_item($admin, $categoryId, $firstPubUserId, 'Publish event target');

// Push the listing's publish event outside the (default 30-day) quota window --
// both columns, matching what a real old post looks like -- so the pin below
// actually distinguishes the two columns rather than agreeing by coincidence
// (a bump that lands inside the same window either way proves nothing).
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_pub_date = DATE_SUB(NOW(), INTERVAL 40 DAY),'
    . ' dt_first_pub_date = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE pk_i_id = ' . $firstPubItemId
);
pin('an old post outside the window does not count toward the quota', 0, Entitlements::publishQuotaUsed($firstPubUserId));

// Simulates exactly what item.bump's apply() does: move dt_pub_date and nothing
// else. dt_first_pub_date, set once at insert, is untouched -- so this old
// listing must NOT reappear in the quota just because it was resorted.
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_pub_date = NOW() WHERE pk_i_id = ' . $firstPubItemId
);
pin('bumping an old listing back to the top does not refill the quota', 0, Entitlements::publishQuotaUsed($firstPubUserId));

seed_item($admin, $categoryId, $firstPubUserId, 'A second, genuine post');
pin('a genuine new post does count toward the quota', 1, Entitlements::publishQuotaUsed($firstPubUserId));

/* ----------------------------------------------------------------------------
 * Billing::spend(). The debit and the feature's effect have to move together:
 * insufficient credit must touch neither, and a feature that reports failure
 * must give its credits back rather than keep them.
 * ------------------------------------------------------------------------- */
harness_section('Billing: spend');

$spendUserId = seed_user($admin, 'spender', 'spender@example.test');

FeatureRegistry::instance()->register('test.spend.costly', array(
    'label'    => 'Too expensive to afford',
    'consumes' => Feature::CONSUMES_QUANTITY,
    'price'    => 999999,
    'apply'    => static function (int $userId) {
        // Only reachable if the debit went through despite insufficient funds --
        // its own grant must never show up either.
        Entitlements::grant($userId, 'test.spend.costly.granted', 1, null);

        return true;
    },
));

$balanceBefore = Wallet::balance($spendUserId);
check(
    'spending on a feature priced above the balance fails',
    Billing::spend($spendUserId, 'test.spend.costly') === false
);
pin('a failed spend leaves the balance untouched', $balanceBefore, Wallet::balance($spendUserId));
pin(
    'a failed spend never reaches the feature\'s effect',
    0,
    Entitlements::quantity($spendUserId, 'test.spend.costly.granted')
);

Wallet::credit($spendUserId, 100, Wallet::REASON_GRANT);

FeatureRegistry::instance()->register('test.spend.rejected', array(
    'label'    => 'Always refuses to apply',
    'consumes' => Feature::CONSUMES_QUANTITY,
    'price'    => 10,
    'apply'    => static function (int $userId) {
        return false;
    },
));

$balanceBefore = Wallet::balance($spendUserId);
$ledgerBefore  = $ledgerCount($spendUserId);
check(
    'spending on a feature whose apply() fails reports failure',
    Billing::spend($spendUserId, 'test.spend.rejected') === false
);
pin('a rejected apply() rolls the debit back, not just the entitlement', $balanceBefore, Wallet::balance($spendUserId));
pin('a rolled-back spend writes no ledger row', $ledgerBefore, $ledgerCount($spendUserId));

/* ----------------------------------------------------------------------------
 * Packages. Checkout builds the order from the package row, never from
 * anything the browser sent -- this pins that the row's own figures are what
 * land on the order, the same way CWebBilling::checkoutPost() reads them.
 * ------------------------------------------------------------------------- */
harness_section('Packages: price reaches the order unchanged');

$packageId = Packages::create(array(
    's_name'     => 'Test bundle',
    'i_amount'   => 5_000_000,
    's_currency' => 'USD',
    'i_credits'  => 250,
));
$package = Packages::find($packageId);

$packageOrder = Orders::create(
    $spendUserId,
    'fake',
    (int) $package['i_amount'],
    (string) $package['s_currency'],
    (int) $package['i_credits']
);

pin('the order amount comes from the package row', 5_000_000, $packageOrder->getAmount());
pin('the order credits come from the package row', 250, $packageOrder->getCredits());
pin('the order currency comes from the package row', 'USD', $packageOrder->getCurrency());

/* ----------------------------------------------------------------------------
 * ItemUpgrades: granting. The unique key on (item, upgrade) is the point of the
 * table -- a second purchase has to extend the row that already exists, never
 * grow a second one, and the extension has to compound onto whatever time is
 * left rather than discard it.
 * ------------------------------------------------------------------------- */
harness_section('ItemUpgrades: grant upserts and compounds');

$upgradeItemCount = static function (int $itemId, string $upgrade) use ($admin): int {
    $res = $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_item_upgrade'
        . ' WHERE fk_i_item_id = ' . $itemId . " AND s_upgrade = '" . $upgrade . "'"
    );

    return (int) $res->fetch_assoc()['c'];
};
$upgradeExpiration = static function (int $itemId, string $upgrade) use ($admin): ?string {
    $res = $admin->query(
        'SELECT dt_expiration FROM ' . DB_TABLE_PREFIX . 't_item_upgrade'
        . ' WHERE fk_i_item_id = ' . $itemId . " AND s_upgrade = '" . $upgrade . "'"
    );

    return $res->fetch_assoc()['dt_expiration'] ?? null;
};

$grantItemId = seed_item($admin, $categoryId, $userId, 'Grant target');

check('a fresh grant creates a row', ItemUpgrades::grant($grantItemId, 'test.upgrade', 10, null));
pin('the fresh grant writes exactly one row', 1, $upgradeItemCount($grantItemId, 'test.upgrade'));
$firstUpgradeExpiration = $upgradeExpiration($grantItemId, 'test.upgrade');
check('the fresh grant has an expiration', $firstUpgradeExpiration !== null);

check('granting the same upgrade again succeeds', ItemUpgrades::grant($grantItemId, 'test.upgrade', 5, null));
pin('a second grant still writes exactly one row, not a second', 1, $upgradeItemCount($grantItemId, 'test.upgrade'));
pin(
    'the extension compounds onto the current expiry, not onto now',
    date('Y-m-d H:i:s', strtotime($firstUpgradeExpiration) + 5 * 86400),
    $upgradeExpiration($grantItemId, 'test.upgrade')
);

/* ----------------------------------------------------------------------------
 * ItemUpgrades: reading. has()/active() must show a live or permanent row and
 * ignore a lapsed one, whether the item was primed or read cold.
 * ------------------------------------------------------------------------- */
harness_section('ItemUpgrades: has()/active() ignore a lapsed row');

$readItemId = seed_item($admin, $categoryId, $userId, 'Read target');

ItemUpgrades::grant($readItemId, 'test.live', 10, null);
$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_upgrade'
    . ' (fk_i_item_id, s_upgrade, dt_expiration, dt_date)'
    . ' VALUES (' . $readItemId . ", 'test.lapsed', '"
    . date('Y-m-d H:i:s', time() - 3600) . "', NOW())"
);
$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_upgrade'
    . ' (fk_i_item_id, s_upgrade, dt_expiration, dt_date)'
    . ' VALUES (' . $readItemId . ", 'test.permanent', NULL, NOW())"
);

check('a live row is held', ItemUpgrades::has($readItemId, 'test.live'));
check('a permanent row is held', ItemUpgrades::has($readItemId, 'test.permanent'));
check('a lapsed row is not held', ItemUpgrades::has($readItemId, 'test.lapsed') === false);

$active = ItemUpgrades::active($readItemId);
check('active() includes the live upgrade', in_array('test.live', $active, true));
check('active() includes the permanent upgrade', in_array('test.permanent', $active, true));
check('active() excludes the lapsed upgrade', !in_array('test.lapsed', $active, true));

pin(
    'expiresAt() returns the raw value even for a lapsed row',
    $upgradeExpiration($readItemId, 'test.lapsed'),
    ItemUpgrades::expiresAt($readItemId, 'test.lapsed')
);
pin('expiresAt() reads null for an upgrade the item never had', null, ItemUpgrades::expiresAt($readItemId, 'test.never'));

/* ----------------------------------------------------------------------------
 * ItemUpgrades: purge. Only a lapsed row is fair game -- a live one and a
 * permanent one both have to survive the sweep untouched.
 * ------------------------------------------------------------------------- */
harness_section('ItemUpgrades: purge');

$purged = ItemUpgrades::purge();
check('purge removes at least the one lapsed row seeded above', $purged >= 1);
pin('the lapsed row is gone', 0, $upgradeItemCount($readItemId, 'test.lapsed'));
pin('the live row survives the sweep', 1, $upgradeItemCount($readItemId, 'test.live'));
pin('the permanent row survives the sweep', 1, $upgradeItemCount($readItemId, 'test.permanent'));
pin('a second purge finds nothing left to remove', 0, ItemUpgrades::purge());

/* ----------------------------------------------------------------------------
 * ItemUpgrades: prime() and the memoized single-item fallback. The property
 * that matters is that a primed (or once-read) item costs no further query --
 * proved here by deleting the underlying row with raw SQL after the read and
 * showing the cached answer does not notice, while an item that was never
 * read at all sees the delete immediately.
 * ------------------------------------------------------------------------- */
harness_section('ItemUpgrades: prime() batches, and a cached read issues no further query');

$primeUserId = seed_user($admin, 'primeuser', 'primeuser@example.test');
$primeItemA  = seed_item($admin, $categoryId, $primeUserId, 'Primed A');
$primeItemB  = seed_item($admin, $categoryId, $primeUserId, 'Primed B');
$primeItemC  = seed_item($admin, $categoryId, $primeUserId, 'Never primed');

ItemUpgrades::grant($primeItemA, 'test.prime', 10, null);
ItemUpgrades::grant($primeItemB, 'test.prime', 10, null);
ItemUpgrades::grant($primeItemC, 'test.prime', 10, null);

ItemUpgrades::prime(array($primeItemA, $primeItemB));

check('a primed item with a row reports it held', ItemUpgrades::has($primeItemA, 'test.prime'));
check('a second primed item with a row reports it held', ItemUpgrades::has($primeItemB, 'test.prime'));

$admin->query(
    'DELETE FROM ' . DB_TABLE_PREFIX . "t_item_upgrade WHERE s_upgrade = 'test.prime'"
    . ' AND fk_i_item_id IN (' . $primeItemA . ', ' . $primeItemB . ', ' . $primeItemC . ')'
);

check(
    'a primed item still reads its upgrade after the row is deleted underneath it -- it read the cache, not a fresh query',
    ItemUpgrades::has($primeItemA, 'test.prime') === true
);
check('the second primed item is unaffected too', ItemUpgrades::has($primeItemB, 'test.prime') === true);
check(
    'an item never primed reflects the delete immediately -- its read was not cached',
    ItemUpgrades::has($primeItemC, 'test.prime') === false
);

/* The single-item fallback (an item nothing ever primed) has to memoize its own
 * first read too, so a second helper called on the same item right after the
 * first costs nothing further -- proved the same way. */
$fallbackItemId = seed_item($admin, $categoryId, $primeUserId, 'Fallback memoized');
ItemUpgrades::grant($fallbackItemId, 'test.fallback', 10, null);

check('a fresh (unprimed) read finds the row', ItemUpgrades::has($fallbackItemId, 'test.fallback'));

$admin->query(
    'DELETE FROM ' . DB_TABLE_PREFIX . "t_item_upgrade WHERE s_upgrade = 'test.fallback' AND fk_i_item_id = "
    . $fallbackItemId
);

check(
    'a second call for the same never-primed item is memoized -- it still reads held, not the row just deleted',
    ItemUpgrades::has($fallbackItemId, 'test.fallback') === true
);

/* prime() has to populate the cache for every id given in one call, including
 * an id with no rows at all -- "primed, holds nothing" must not fall through
 * to a fresh query just because there was nothing to remember. */
$emptyItemId = seed_item($admin, $categoryId, $primeUserId, 'No upgrades at all');
ItemUpgrades::prime(array($emptyItemId));
$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_upgrade'
    . ' (fk_i_item_id, s_upgrade, dt_expiration, dt_date)'
    . ' VALUES (' . $emptyItemId . ", 'test.sneaked_in', NULL, NOW())"
);
check(
    'an item primed with no rows stays "no upgrades" even if a row appears afterward -- it read the cache, not the table',
    ItemUpgrades::active($emptyItemId) === array()
);

/* A write has to invalidate the cache entry it affects, or a purchase made
 * right after a primed read would be invisible to the very next read in the
 * same request -- the cache must never paper over the seller's own purchase. */
$writeInvalidatesId = seed_item($admin, $categoryId, $primeUserId, 'Write invalidates cache');
ItemUpgrades::prime(array($writeInvalidatesId));
check('primed with nothing before the grant', ItemUpgrades::has($writeInvalidatesId, 'test.invalidate') === false);
ItemUpgrades::grant($writeInvalidatesId, 'test.invalidate', 10, null);
check(
    'a grant right after a primed read is visible on the very next read, not stale',
    ItemUpgrades::has($writeInvalidatesId, 'test.invalidate') === true
);

/* ----------------------------------------------------------------------------
 * osc_prime_item_upgrades(): the public helper. Accepts item rows and bare ids
 * in the same call, since a theme has whichever is already to hand, and must
 * cost a site with billing switched off nothing at all.
 * ------------------------------------------------------------------------- */
harness_section('osc_prime_item_upgrades(): rows or ids, no-op while billing is off');

$helperItemId  = seed_item($admin, $categoryId, $primeUserId, 'Helper primed by id');
$helperItemId2 = seed_item($admin, $categoryId, $primeUserId, 'Helper primed by row');
ItemUpgrades::grant($helperItemId, 'test.helper', 10, null);
ItemUpgrades::grant($helperItemId2, 'test.helper', 10, null);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
osc_prime_item_upgrades(array($helperItemId));
$admin->query(
    'DELETE FROM ' . DB_TABLE_PREFIX . "t_item_upgrade WHERE s_upgrade = 'test.helper' AND fk_i_item_id = "
    . $helperItemId
);
check(
    'osc_prime_item_upgrades() is a no-op while billing is off -- the delete is visible, nothing was cached',
    ItemUpgrades::has($helperItemId, 'test.helper') === false
);
ItemUpgrades::grant($helperItemId, 'test.helper', 10, null); // restore for the next check

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

osc_prime_item_upgrades(array(
    $helperItemId,                        // bare id
    array('pk_i_id' => $helperItemId2),   // item row, the shape a search result comes in
));
$admin->query(
    'DELETE FROM ' . DB_TABLE_PREFIX . "t_item_upgrade WHERE s_upgrade = 'test.helper'"
    . ' AND fk_i_item_id IN (' . $helperItemId . ', ' . $helperItemId2 . ')'
);
check('a bare id in the array is primed', ItemUpgrades::has($helperItemId, 'test.helper') === true);
check(
    'an item row (pk_i_id) in the same array is primed too',
    ItemUpgrades::has($helperItemId2, 'test.helper') === true
);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * Bump: the cooldown IS the item.bump row's own expiry, not a second concept.
 * Billing::spend('item.bump') has to move dt_pub_date and debit together, and
 * a failed apply -- an item that does not exist -- has to roll both back.
 * ------------------------------------------------------------------------- */
harness_section('Billing: item.bump');

// hBilling.php registers item.bump only when billing_bump_enabled was already on at
// load time, which it was not for this process -- flipping the preference here needs
// the same registration re-run the admin Upgrades save triggers. Billing itself goes
// back on because spend() refuses outright while the master switch is off.
osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_bump_enabled', '1', 'osclass', 'BOOLEAN');
osc_set_preference('billing_bump_credits', '5', 'osclass', 'INTEGER');
osc_set_preference('billing_bump_cooldown_hours', '24', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_item_upgrades();

$bumpUserId = seed_user($admin, 'bumper', 'bumper@example.test');
Wallet::credit($bumpUserId, 100, Wallet::REASON_GRANT);
$bumpItemId = seed_item($admin, $categoryId, $bumpUserId, 'Bump target');
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_pub_date = DATE_SUB(NOW(), INTERVAL 2 DAY)'
    . ' WHERE pk_i_id = ' . $bumpItemId
);

$pubDateOf = static function (int $itemId) use ($admin): string {
    return $admin->query(
        'SELECT dt_pub_date FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $itemId
    )->fetch_assoc()['dt_pub_date'];
};

$balanceBeforeBump = Wallet::balance($bumpUserId);
$pubDateBeforeBump = $pubDateOf($bumpItemId);

check('bump.item registers once its preference is on', FeatureRegistry::instance()->get('item.bump') !== null);
check(
    'spending on item.bump succeeds',
    Billing::spend($bumpUserId, 'item.bump', array('itemId' => $bumpItemId, 'ref_type' => 'item', 'ref_id' => $bumpItemId))
);
check('bump moves dt_pub_date forward', $pubDateOf($bumpItemId) > $pubDateBeforeBump);
pin('bump debits its price', $balanceBeforeBump - 5, Wallet::balance($bumpUserId));
check('the bump leaves a live cooldown row', ItemUpgrades::has($bumpItemId, 'item.bump'));

// The cooldown is enforced by callers reading has() before allowing another bump
// (CWebBilling::upgradePost()'s already-held check) -- not by spend() itself, which
// has no opinion on cooldowns. This pins the primitive that check relies on.
check('the cooldown blocks while the row is live', ItemUpgrades::has($bumpItemId, 'item.bump') === true);

// A separate item, read via has() for the first time only after its cooldown
// row is fast-forwarded into the past. $bumpItemId above was already read (and
// is now memoized) before this rewrite, and a raw SQL edit of dt_expiration --
// there is no real-world equivalent; wall-clock time simply passes over the
// row untouched -- does not retroactively invalidate an already-cached read.
// A never-read item's first read still has to see the row as it stands.
$lapsedBumpItemId = seed_item($admin, $categoryId, $bumpUserId, 'Bump target, lapses');
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_pub_date = DATE_SUB(NOW(), INTERVAL 2 DAY)'
    . ' WHERE pk_i_id = ' . $lapsedBumpItemId
);
check(
    'spending on item.bump succeeds for the lapse target too',
    Billing::spend(
        $bumpUserId,
        'item.bump',
        array('itemId' => $lapsedBumpItemId, 'ref_type' => 'item', 'ref_id' => $lapsedBumpItemId)
    )
);
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item_upgrade SET dt_expiration = \''
    . date('Y-m-d H:i:s', time() - 60) . "' WHERE fk_i_item_id = " . $lapsedBumpItemId . " AND s_upgrade = 'item.bump'"
);
check('the cooldown lifts once the row lapses', ItemUpgrades::has($lapsedBumpItemId, 'item.bump') === false);

$balanceBeforeBogus = Wallet::balance($bumpUserId);
check(
    'bumping an item that does not exist fails',
    Billing::spend($bumpUserId, 'item.bump', array('itemId' => 999999999, 'ref_type' => 'item', 'ref_id' => 999999999)) === false
);
pin('a failed bump apply leaves the balance untouched', $balanceBeforeBogus, Wallet::balance($bumpUserId));

osc_set_preference('billing_bump_enabled', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * The price filter now carries the spending user. Billing::spend() already
 * knows who is spending; the filter has to be told, not left to guess through
 * osc_logged_user_id(), which is wrong for an admin or a cron acting on
 * someone else's behalf.
 * ------------------------------------------------------------------------- */
harness_section('Feature: price() threads the user id through the filter');

$capturedPriceUserId = 'not called';
osc_add_hook('billing_feature_price', static function ($price, $featureId, $userId) use (&$capturedPriceUserId) {
    if ($featureId === 'test.price.witness') {
        $capturedPriceUserId = $userId;
    }

    return $price;
});

FeatureRegistry::instance()->register('test.price.witness', array(
    'label'    => 'Price filter witness',
    'consumes' => Feature::CONSUMES_QUANTITY,
    'price'    => 7,
    'apply'    => static function (int $userId) {
        return true;
    },
));

$witnessUserId = seed_user($admin, 'witness', 'witness@example.test');
Wallet::credit($witnessUserId, 20, Wallet::REASON_GRANT);
Billing::spend($witnessUserId, 'test.price.witness');

pin('the price filter receives the spending user id', $witnessUserId, $capturedPriceUserId);

/* ----------------------------------------------------------------------------
 * Feature::duration() / Billing::spend(): the resolved duration has to reach
 * apply() through $ctx['days'], filter included -- otherwise a plugin changing
 * billing_feature_duration has no effect on what a purchase actually grants,
 * because every built-in apply() would keep reading its own preference instead.
 * ------------------------------------------------------------------------- */
harness_section('Feature: duration() reaches apply() through spend(), filter included');

$capturedDays = 'not called';
osc_add_hook('billing_feature_duration', static function ($days, $featureId, $userId) {
    if ($featureId === 'test.duration.witness') {
        return 99; // override whatever the spec declared, proving the filter fires
    }

    return $days;
});

FeatureRegistry::instance()->register('test.duration.witness', array(
    'label'    => 'Duration filter witness',
    'consumes' => Feature::CONSUMES_DURATION,
    'price'    => 0,
    'duration' => 10,
    'apply'    => static function (int $userId, array $ctx) use (&$capturedDays) {
        $capturedDays = $ctx['days'] ?? null;

        return true;
    },
));

$durationUserId = seed_user($admin, 'durationwitness', 'durationwitness@example.test');
Billing::spend($durationUserId, 'test.duration.witness');

pin(
    'a registered billing_feature_duration filter changes how many days a purchase grants',
    99,
    $capturedDays
);
pin(
    'Feature::duration() itself reflects the filter, matching what spend() threaded through',
    99,
    FeatureRegistry::instance()->get('test.duration.witness')->duration($durationUserId)
);

/* A plugin calling apply() directly still has to work -- it never goes through
 * spend(), so $ctx carries no 'days' key at all, and the witness above simply
 * records whatever it was given. */
$capturedDays = 'not called';
FeatureRegistry::instance()->get('test.duration.witness')->apply($durationUserId, array());
pin('apply() called directly with no context sees no days key at all', null, $capturedDays);

/* The same fallback has to hold for a real built-in, not only the witness:
 * item.highlight's own apply() must fall back to osc_billing_highlight_days()
 * when it is called with no 'days' in context, exactly as a plugin calling
 * apply() directly would see. */
osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_highlight_enabled', '1', 'osclass', 'BOOLEAN');
osc_set_preference('billing_highlight_credits', '0', 'osclass', 'INTEGER');
osc_set_preference('billing_highlight_days', '12', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_item_upgrades();

$fallbackItemId = seed_item($admin, $categoryId, $userId, 'Highlight fallback target');
$beforeFallback = time();
FeatureRegistry::instance()->get('item.highlight')->apply($userId, array('itemId' => $fallbackItemId));
$afterFallback = time();

$highlightExpiresAt = ItemUpgrades::expiresAt($fallbackItemId, 'item.highlight');
check('apply() called directly grants the highlight at all', $highlightExpiresAt !== null);
$highlightExpiresTs = $highlightExpiresAt !== null ? strtotime($highlightExpiresAt) : 0;
check(
    'apply() called directly with no context falls back to the preference (12 days), not zero',
    $highlightExpiresTs >= $beforeFallback + 12 * 86400 - 2
    && $highlightExpiresTs <= $afterFallback + 12 * 86400 + 2
);

osc_set_preference('billing_highlight_enabled', '0', 'osclass', 'BOOLEAN');
osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * Entitlements: capacity(). A ceiling that is read, never spent -- the default
 * with nothing held, the granted quantity once one exists, -1 for an unlimited
 * row, and a lapsed row must not count at all. consume() has to refuse a
 * feature the registry declares capacity, so nothing can drain a ceiling by
 * mistake.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: capacity()');

$capUserId = seed_user($admin, 'capuser', 'capuser@example.test');

pin('capacity with no entitlement reads the default', 7, Entitlements::capacity($capUserId, 'test.capacity', 7));

Entitlements::grant($capUserId, 'test.capacity', 15, null);
pin('capacity reads the granted quantity', 15, Entitlements::capacity($capUserId, 'test.capacity', 7));

$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_user_entitlement'
    . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
    . ' VALUES (' . $capUserId . ", 'test.capacity.unlimited', NULL, NULL, 'grant', NOW())"
);
pin('capacity reads -1 for an unlimited row', -1, Entitlements::capacity($capUserId, 'test.capacity.unlimited', 0));

$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_user_entitlement'
    . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
    . ' VALUES (' . $capUserId . ", 'test.capacity.lapsed', 99, '"
    . date('Y-m-d H:i:s', time() - 3600) . "', 'grant', NOW())"
);
pin('a lapsed row does not count toward capacity', 3, Entitlements::capacity($capUserId, 'test.capacity.lapsed', 3));

FeatureRegistry::instance()->register('test.capacity.guarded', array(
    'label'    => 'Guarded capacity feature',
    'consumes' => Feature::CONSUMES_CAPACITY,
    'apply'    => static function (int $userId) {
        return true;
    },
));
Entitlements::grant($capUserId, 'test.capacity.guarded', 20, null);
check(
    'consume() refuses a feature the registry declares capacity',
    Entitlements::consume($capUserId, 'test.capacity.guarded', 1) === false
);
pin(
    'the refused consume leaves the ceiling exactly as granted',
    20,
    Entitlements::capacity($capUserId, 'test.capacity.guarded', 0)
);

/* A capacity entitlement can only ever RAISE a limit, never lower one -- $default
 * is a floor, not merely what to return when there is no row at all. Without this,
 * a global cap of 20 plus a bought entitlement of 10 would leave the paying seller
 * with 10. */
Entitlements::grant($capUserId, 'test.capacity.floor', 3, null);
pin(
    'capacity() returns the global default when it is higher than the entitlement',
    10,
    Entitlements::capacity($capUserId, 'test.capacity.floor', 10)
);

/* ----------------------------------------------------------------------------
 * hBilling: the entitlement-aware siblings of osc_max_images_per_item() and
 * osc_items_wait_time(). The two old helpers are a compatibility contract with
 * every third-party theme and plugin, so the new ones must read back exactly
 * the same value whenever billing is off or the user holds nothing, and only
 * diverge once an entitlement is actually granted.
 * ------------------------------------------------------------------------- */
harness_section('hBilling: entitlement-aware limit helpers');

$limitUserId = seed_user($admin, 'limituser', 'limituser@example.test');

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
pin(
    'photo cap matches the plain preference while billing is off',
    osc_max_images_per_item(),
    osc_max_images_for_user($limitUserId)
);
pin(
    'the posting wait matches the plain preference while billing is off',
    osc_items_wait_time(),
    osc_items_wait_time_for_user($limitUserId)
);

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
pin(
    'photo cap still matches the plain preference for a user holding nothing',
    osc_max_images_per_item(),
    osc_max_images_for_user($limitUserId)
);
check(
    'the posting wait still matches the plain preference for a user holding nothing',
    osc_items_wait_time_for_user($limitUserId) === osc_items_wait_time()
);

/* The property that matters is "never lowers", not "matches the grant" -- the
 * scratch database's own global cap (numImages@items is unset here, which reads
 * as 0 = unlimited) is the case that actually catches a regression: a capacity
 * entitlement must not turn an unlimited global cap into a finite one. */
pin('the global photo cap in this fixture is unlimited', 0, osc_max_images_per_item());
Entitlements::grant($limitUserId, 'listing.photos', 25, null);
pin(
    'an unlimited global photo cap stays unlimited for a seller holding a photos entitlement',
    0,
    osc_max_images_for_user($limitUserId)
);

/* With a finite global cap, the entitlement may raise it... */
$raisedCapUserId = seed_user($admin, 'raisedcap', 'raisedcap@example.test');
osc_set_preference('numImages@items', '4', 'osclass', 'INTEGER');
osc_reset_preferences();
pin(
    'a finite global cap applies to a user holding nothing',
    4,
    osc_max_images_for_user($raisedCapUserId)
);
Entitlements::grant($raisedCapUserId, 'listing.photos', 20, null);
pin('a listing.photos entitlement raises a finite global cap', 20, osc_max_images_for_user($raisedCapUserId));

/* ...but a smaller entitlement must never lower it. */
$belowCapUserId = seed_user($admin, 'belowcap', 'belowcap@example.test');
Entitlements::grant($belowCapUserId, 'listing.photos', 2, null);
pin('a capacity entitlement below the global cap never lowers it', 4, osc_max_images_for_user($belowCapUserId));

osc_set_preference('numImages@items', '0', 'osclass', 'INTEGER');
osc_reset_preferences();

Entitlements::grant($limitUserId, 'listing.no_wait', null, 30);
pin('a listing.no_wait entitlement waives the wait entirely', 0, osc_items_wait_time_for_user($limitUserId));

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
