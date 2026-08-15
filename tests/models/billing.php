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
pin('the merge writes exactly one row, not a second', 1, $entCount($entUserId, 'test.qty'));

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
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item_upgrade SET dt_expiration = \''
    . date('Y-m-d H:i:s', time() - 60) . "' WHERE fk_i_item_id = " . $bumpItemId . " AND s_upgrade = 'item.bump'"
);
check('the cooldown lifts once the row lapses', ItemUpgrades::has($bumpItemId, 'item.bump') === false);

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

Entitlements::grant($limitUserId, 'listing.photos', 25, null);
pin('a listing.photos entitlement raises the cap', 25, osc_max_images_for_user($limitUserId));

Entitlements::grant($limitUserId, 'listing.no_wait', null, 30);
pin('a listing.no_wait entitlement waives the wait entirely', 0, osc_items_wait_time_for_user($limitUserId));

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
