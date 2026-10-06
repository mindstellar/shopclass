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

// Premium::expire() and Item::updateExpirationDate() read osc_item_is_counted() and osc_isExpired().
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

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
// The billing URL helpers delegate to the core route table; hDefines.php, where
// osc_core_url() lives, pulls in far more than this file needs.
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = array())
    {
        return \mindstellar\routing\CoreRoutes::url($name, $args);
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
// Category's constructor branches on OC_ADMIN and reaches osc_base_url() through its
// cache-key builder (osc_validate_category(), reached by the ItemActions::add()
// choke-point section below, goes through Category) -- the same stand-ins
// tests/models/item.php uses for the same reason.
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
// The account-menu hook labels its entries with _m(), whose real definition drags in
// the whole translation stack; the labels are not what these pins read.
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
// Billing::refundThroughGateway() words its refusals with __(); hTranslations.php is
// never loaded here.
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}
// EntitlementStore::withinFreeQuota()/canPublish() read osc_billing_free_live_listings()
// and friends, which live here rather than in the default bootstrap requires.
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hBilling.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\DashboardLinkGateway;
use mindstellar\billing\EntitlementStore;
use mindstellar\billing\Feature;
use mindstellar\billing\FeatureRegistry;
use mindstellar\billing\ItemUpgradeStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PackageStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Premium;
use mindstellar\billing\Receipts;
use mindstellar\billing\RefundableGateway;
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

/**
 * A gateway that can refund. The test sets what refund() does and counts the calls.
 */
final class FakeRefundGateway implements RefundableGateway, DashboardLinkGateway
{
    /** @var callable(Order):CallbackResult|null */
    public $onRefund = null;

    public int $calls = 0;

    public bool $throwOnCheckout = false;

    /** @var callable(Order):?string|null */
    public $onDashboard = null;

    public function __construct(private string $id = 'refundy')
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return 'Refundy';
    }

    public function getSupportedCurrencies(): array
    {
        return array('USD');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(Order $order): CheckoutIntent
    {
        if ($this->throwOnCheckout) {
            throw new RuntimeException('secret detail', 42);
        }

        return CheckoutIntent::redirect('https://example.test/pay/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored();
    }

    public function dashboardUrl(Order $order): ?string
    {
        return $this->onDashboard !== null ? ($this->onDashboard)($order) : null;
    }

    public function refund(Order $order): CallbackResult
    {
        $this->calls++;

        return $this->onRefund !== null
            ? ($this->onRefund)($order)
            : CallbackResult::refunded($order->getId(), 're_' . $order->getId());
    }
}

// Receipts are e-mailed by default; only the receipts section below turns them on.
osc_set_preference(Receipts::PREF_EMAIL, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

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

$order = OrderStore::create($userId, 'fake', 9_990_000, 'USD', 100, array('sku' => 'credits-100'));
pin('new orders start pending', Order::STATUS_PENDING, $order->getStatus());
pin('amount is stored in micros', 9990000, $order->getAmount());
pin('metadata round-trips', 'credits-100', OrderStore::find($order->getId())->meta('sku'));
pin('search filters by status and gateway', true, in_array($order->getId(), array_map(static fn ($o) => $o->getId(), OrderStore::search(array('status' => Order::STATUS_PENDING, 'gateway' => 'fake'), 50)), true));

check('settling a pending order succeeds', OrderStore::settle($order->getId(), Order::STATUS_PAID, 'ext_1'));
check('re-settling the same order is refused', OrderStore::settle($order->getId(), Order::STATUS_PAID, 'ext_1') === false);
pin('settled order reads back as paid', Order::STATUS_PAID, OrderStore::find($order->getId())->getStatus());
pin('external reference is stored', 'ext_1', OrderStore::find($order->getId())->getExternalRef());
check('paid orders carry a paid date', OrderStore::find($order->getId())->getPaidDate() !== null);

pin(
    'lookup by gateway reference finds the order',
    $order->getId(),
    OrderStore::findByGatewayRef('fake', 'ext_1')->getId()
);
check(
    'lookup is scoped to the gateway',
    OrderStore::findByGatewayRef('other', 'ext_1') === null
);

/* ----------------------------------------------------------------------------
 * Fulfilment. Settling and crediting have to be one atomic step.
 * ------------------------------------------------------------------------- */
harness_section('Billing: markPaid');

$balance = Wallet::balance($userId);
$order   = OrderStore::create($userId, 'fake', 1_000_000, 'USD', 40);

check('markPaid settles the order', Billing::markPaid($order, 'ext_2') === true);
pin('markPaid mints the credits', $balance + 40, Wallet::balance($userId));
pin('order is paid', Order::STATUS_PAID, OrderStore::find($order->getId())->getStatus());

check('markPaid on an already-paid order reports no change', Billing::markPaid($order, 'ext_2') === false);
pin('a repeated markPaid mints nothing further', $balance + 40, Wallet::balance($userId));

/* ----------------------------------------------------------------------------
 * Callback verification. A callback is attacker-reachable input.
 * ------------------------------------------------------------------------- */
harness_section('Billing: callback verification');

$gateway = new FakeGateway('fake');
PaymentGatewayRegistry::getInstance()->register($gateway);

$balance = Wallet::balance($userId);
$order   = OrderStore::create($userId, 'fake', 5_000_000, 'USD', 200);

$gateway->verdict = CallbackResult::paid($order->getId(), 'ext_3', 1, 'USD');
$result = Billing::handleCallback('fake', array());
pin('a short-paid callback is ignored', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('a short-paid callback mints nothing', $balance, Wallet::balance($userId));
pin('a short-paid order stays pending', Order::STATUS_PENDING, OrderStore::find($order->getId())->getStatus());

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
PaymentGatewayRegistry::getInstance()->register($rival);

$balance     = Wallet::balance($otherId);
$rivalTarget = OrderStore::create($otherId, 'fake', 2_000_000, 'USD', 500);
$rival->verdict = CallbackResult::paid($rivalTarget->getId(), 'ext_4', 2_000_000, 'USD');

$result = Billing::handleCallback('rival', array());
pin('one gateway cannot settle another\'s order', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('the cross-gateway attempt mints nothing', $balance, Wallet::balance($otherId));
pin(
    'the targeted order stays pending',
    Order::STATUS_PENDING,
    OrderStore::find($rivalTarget->getId())->getStatus()
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
$order   = OrderStore::create($userId, 'fake', 1_000_000, 'USD', 60);
Billing::markPaid($order, 'ext_5');
pin('paid order credits', $balance + 60, Wallet::balance($userId));

check('refund reverses a paid order', Billing::refund(OrderStore::find($order->getId())) === true);
pin('refund takes the credits back', $balance, Wallet::balance($userId));
pin('refunded order reads back as refunded', Order::STATUS_REFUNDED, OrderStore::find($order->getId())->getStatus());
check(
    'refunding twice reports no change',
    Billing::refund(OrderStore::find($order->getId())) === false
);
pin('a repeated refund takes nothing further', $balance, Wallet::balance($userId));

/* ----------------------------------------------------------------------------
 * Refund through the gateway: the admin Refund button.
 * ------------------------------------------------------------------------- */
harness_section('Billing: refundThroughGateway');

$refundy = new FakeRefundGateway('refundy');
PaymentGatewayRegistry::getInstance()->register($refundy);

$orderStatus = static fn (Order $o): string => OrderStore::find($o->getId())->getStatus();

$plainOrder = OrderStore::create($userId, 'fake', 1_000_000, 'USD', 5);
Billing::markPaid($plainOrder, 'ext_plain');
check('a paid order of a plain gateway offers no gateway refund', Billing::refundableGateway(OrderStore::find($plainOrder->getId())) === null);

$balance = Wallet::balance($userId);
$order   = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 70);
check('a pending order offers none', Billing::refundableGateway($order) === null);
Billing::markPaid($order, 'ext_r1');
check('a paid order of a refundable gateway offers one', Billing::refundableGateway(OrderStore::find($order->getId())) === $refundy);

$result = Billing::refundThroughGateway($order);
pin('a refunded answer is accepted', CallbackResult::OUTCOME_REFUNDED, $result->getOutcome());
pin('the order is refunded', Order::STATUS_REFUNDED, $orderStatus($order));
pin('the credits are taken back', $balance, Wallet::balance($userId));

/* $order is the stale pending copy; the order is read again, so the press is refused. */
$calls  = $refundy->calls;
$result = Billing::refundThroughGateway($order);
pin('a second press is refused', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('a second press does not ask the provider', $calls, $refundy->calls);
pin('a second press takes nothing further', $balance, Wallet::balance($userId));

$paidRefundy = static function (int $credits = 5) use ($userId): Order {
    $o = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', $credits);
    Billing::markPaid($o, 'ext_' . bin2hex(random_bytes(4)));

    return OrderStore::find($o->getId());
};

$refused = $paidRefundy(30);
$balance = Wallet::balance($userId);

$refundy->onRefund = static fn (Order $o): CallbackResult => CallbackResult::ignored('card expired');
$result = Billing::refundThroughGateway($refused);
pin('an ignored answer is passed back', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('with the provider\'s reason', 'card expired', $result->getMessage());
pin('an ignored answer leaves the order paid', Order::STATUS_PAID, $orderStatus($refused));
pin('an ignored answer takes no credits', $balance, Wallet::balance($userId));
pin('an ignored answer leaves no sent mark', null, OrderStore::find($refused->getId())->meta(OrderStore::REFUND_REQUESTED));
check('so the button stays', Billing::refundableGateway(OrderStore::find($refused->getId())) === $refundy);
$calls = $refundy->calls;
Billing::refundThroughGateway($refused);
pin('and the admin can try again', $calls + 1, $refundy->calls);

$mismatched = $paidRefundy();
$balance    = Wallet::balance($userId);
$refundy->onRefund = static fn (Order $o): CallbackResult => CallbackResult::refunded($o->getId() + 1000);
$result = Billing::refundThroughGateway($mismatched);
pin('a refund for another order id is refused', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('the order stays paid after a mismatched answer', Order::STATUS_PAID, $orderStatus($mismatched));
pin('a mismatched answer takes no credits', $balance, Wallet::balance($userId));

$wrongOutcome = $paidRefundy();
$refundy->onRefund = static fn (Order $o): CallbackResult => CallbackResult::paid($o->getId());
pin('an answer that is not a refund is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundThroughGateway($wrongOutcome)->getOutcome());
pin('the order stays paid after a wrong outcome', Order::STATUS_PAID, $orderStatus($wrongOutcome));

$withMeta = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 1, array('session' => 'cs_keep'));
Billing::markPaid($withMeta, 'ext_meta');
check('the sent mark can be set', OrderStore::markRefundRequested($withMeta->getId()));
pin('the plugin\'s own meta is kept', 'cs_keep', OrderStore::find($withMeta->getId())->meta('session'));
check('and cleared', OrderStore::markRefundRequested($withMeta->getId(), false));
pin('clearing keeps the plugin\'s meta', array('session' => 'cs_keep'), OrderStore::find($withMeta->getId())->getMeta());
check('a pending order cannot be marked', !OrderStore::markRefundRequested(OrderStore::create($userId, 'refundy', 1, 'USD', 1)->getId()));

$threw   = $paidRefundy();
$balance = Wallet::balance($userId);
$refundy->onRefund = static function (Order $o): CallbackResult {
    throw new RuntimeException('provider down');
};
$logged = ini_get('error_log');
ini_set('error_log', tempnam(sys_get_temp_dir(), 'billing-refund'));
$result = Billing::refundThroughGateway($threw);
$errorLog = (string) file_get_contents(ini_get('error_log'));
@unlink(ini_get('error_log'));
ini_set('error_log', (string) $logged);
pin('a throwing gateway is caught', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
check('the exception class is logged', strpos($errorLog, 'RuntimeException') !== false);
check('the exception text is not logged', strpos($errorLog, 'provider down') === false);
check('the provider\'s exception text is not shown', strpos($result->getMessage(), 'provider down') === false);
pin('a throwing gateway leaves the order paid', Order::STATUS_PAID, $orderStatus($threw));
pin('a throwing gateway takes no credits', $balance, Wallet::balance($userId));

/* The provider may have taken the refund before it threw: no second call. */
check('after a throw the order is marked as sent', OrderStore::find($threw->getId())->meta(OrderStore::REFUND_REQUESTED) !== null);
pin('the button is gone', null, Billing::refundableGateway(OrderStore::find($threw->getId())));
$refundy->onRefund = null;
$calls  = $refundy->calls;
$result = Billing::refundThroughGateway($threw);
pin('a second press after a throw is refused', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('a second press after a throw does not call the provider', $calls, $refundy->calls);
check('Record a refund still works', Billing::refund(OrderStore::find($threw->getId())));
pin('and takes the credits back', $balance - 5, Wallet::balance($userId));

pin('a gateway that cannot refund is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundThroughGateway($plainOrder)->getOutcome());
pin('that order stays paid', Order::STATUS_PAID, $orderStatus($plainOrder));

$refundy->onRefund = null;
$calls   = $refundy->calls;
$pending = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 5);
pin('a pending order is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundThroughGateway($pending)->getOutcome());
pin('the provider is not asked for a pending order', $calls, $refundy->calls);
pin('the pending order is unchanged', Order::STATUS_PENDING, $orderStatus($pending));

/* A second request holding the order's lock: refused without asking the provider. */
$refundy->onRefund = null;
$locked = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 5);
Billing::markPaid($locked, 'ext_lock');
$admin->query("SELECT GET_LOCK('" . Billing::refundLockName($locked->getId()) . "', 0)");
$calls  = $refundy->calls;
$result = Billing::refundThroughGateway($locked);
$admin->query("SELECT RELEASE_LOCK('" . Billing::refundLockName($locked->getId()) . "')");
pin('a refund already running is refused', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
pin('with that reason', 'A refund for this order is already running', $result->getMessage());
pin('the provider is not asked while another refund runs', $calls, $refundy->calls);
pin('the locked order stays paid', Order::STATUS_PAID, $orderStatus($locked));
pin('the lock is released afterwards', CallbackResult::OUTCOME_REFUNDED, Billing::refundThroughGateway($locked)->getOutcome());

/* A deleted order is refused, not a fatal. */
$gone = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 5);
Billing::markPaid($gone, 'ext_gone');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_billing_order WHERE pk_i_id = ' . $gone->getId());
pin('an order that no longer exists is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundThroughGateway($gone)->getOutcome());

/* The provider accepts, but another request (a refund webhook) records it first. */
$raced = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 20);
Billing::markPaid($raced, 'ext_race');
$balance = Wallet::balance($userId);
$refundy->onRefund = static function (Order $o): CallbackResult {
    Billing::refund(OrderStore::find($o->getId()));

    return CallbackResult::refunded($o->getId(), 're_race');
};
$accepted = null;
$result   = Billing::refundThroughGateway($raced, $accepted);
pin('a refund recorded by another request is not reported as success', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
check('but the provider accepted it', $accepted === true);
pin('the order is refunded', Order::STATUS_REFUNDED, $orderStatus($raced));
pin('the credits are reversed once', $balance - 20, Wallet::balance($userId));

/* The provider accepts, but the write fails: nothing is recorded and the admin is told. */
$unrecorded = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 20);
Billing::markPaid($unrecorded, 'ext_unrec');
$balance = Wallet::balance($userId);
$ledger  = DB_TABLE_PREFIX . 't_billing_ledger';
$refundy->onRefund = static function (Order $o) use ($admin, $ledger): CallbackResult {
    $admin->query("RENAME TABLE $ledger TO {$ledger}_away");

    return CallbackResult::refunded($o->getId(), 're_unrec');
};
$accepted = null;
ini_set('error_log', tempnam(sys_get_temp_dir(), 'billing-refund'));
$result   = Billing::refundThroughGateway($unrecorded, $accepted);
$errorLog = (string) file_get_contents(ini_get('error_log'));
@unlink(ini_get('error_log'));
ini_set('error_log', (string) $logged);
$admin->query("RENAME TABLE {$ledger}_away TO $ledger");
pin('a failed write is not reported as success', CallbackResult::OUTCOME_IGNORED, $result->getOutcome());
check('the provider accepted it', $accepted === true);
check('the admin is told to record it', strpos($result->getMessage(), 'Record a refund') !== false);
check('the provider reference is logged', strpos($errorLog, 're_unrec') !== false);
pin('the order stays paid', Order::STATUS_PAID, $orderStatus($unrecorded));
pin('no credits are taken', $balance, Wallet::balance($userId));
$refundy->onRefund = null;
$calls = $refundy->calls;
pin('a second press after a failed write is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundThroughGateway($unrecorded)->getOutcome());
pin('a second press after a failed write does not call the provider', $calls, $refundy->calls);
pin('the button is gone after a failed write', null, Billing::refundableGateway(OrderStore::find($unrecorded->getId())));

/* A paid copy of an order that is in fact pending is still refused. */
$stale = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 5);
Billing::markPaid($stale, 'ext_r4');
$stalePaid = OrderStore::find($stale->getId());
Billing::refund($stalePaid);
$balance = Wallet::balance($userId);
pin('a stale paid copy of a refunded order is refused', CallbackResult::OUTCOME_IGNORED, Billing::refundThroughGateway($stalePaid)->getOutcome());
pin('and takes nothing', $balance, Wallet::balance($userId));

/* ----------------------------------------------------------------------------
 * OrderStore::attachRef() and a checkout that throws.
 * ------------------------------------------------------------------------- */
harness_section('Orders: attachRef');

$attach = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 5);
check('a pending order takes a ref', OrderStore::attachRef($attach->getId(), 'refundy', 'cs_test_123'));
pin('the ref is stored', 'cs_test_123', OrderStore::find($attach->getId())->getExternalRef());
pin('the order stays pending', Order::STATUS_PENDING, OrderStore::find($attach->getId())->getStatus());
pin('a callback finds the order by it', $attach->getId(), OrderStore::findByGatewayRef('refundy', 'cs_test_123')->getId());
check('another gateway cannot attach a ref', !OrderStore::attachRef($attach->getId(), 'fake', 'cs_other'));
check('a re-checkout replaces the ref', OrderStore::attachRef($attach->getId(), 'refundy', 'cs_test_456') && OrderStore::attachRef($attach->getId(), 'refundy', 'cs_test_123'));
check('a ref with a space is refused', !OrderStore::attachRef($attach->getId(), 'refundy', 'cs test'));
check('an empty ref is refused', !OrderStore::attachRef($attach->getId(), 'refundy', ''));
check('a ref over 191 characters is refused', !OrderStore::attachRef($attach->getId(), 'refundy', str_repeat('a', 192)));

$twin = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 5);
check('another order\'s ref is refused', !OrderStore::attachRef($twin->getId(), 'refundy', 'cs_test_123'));

check('settle still stores the paid ref', Billing::markPaid(OrderStore::find($attach->getId()), 'pi_paid_123'));
pin('the paid ref replaces the session ref', 'pi_paid_123', OrderStore::find($attach->getId())->getExternalRef());
check('a paid order takes no ref', !OrderStore::attachRef($attach->getId(), 'refundy', 'cs_later'));
pin('the paid ref is kept', 'pi_paid_123', OrderStore::find($attach->getId())->getExternalRef());

OrderStore::settle($twin->getId(), Order::STATUS_FAILED);
check('a failed order takes no ref', !OrderStore::attachRef($twin->getId(), 'refundy', 'cs_failed'));

harness_section('Billing: checkout that throws');

$thrower = new FakeRefundGateway('thrower');
$thrower->throwOnCheckout = true;
PaymentGatewayRegistry::getInstance()->register($thrower);
$broken = OrderStore::create($userId, 'thrower', 1_000_000, 'USD', 5);
$logged = ini_get('error_log');
ini_set('error_log', tempnam(sys_get_temp_dir(), 'billing-checkout'));
$intent   = Billing::checkout($broken);
$errorLog = (string) file_get_contents(ini_get('error_log'));
@unlink(ini_get('error_log'));
ini_set('error_log', (string) $logged);
pin('a throwing checkout reads as unavailable', null, $intent);
check('the class and code are logged', strpos($errorLog, 'RuntimeException (code 42)') !== false);
check('the message is not logged', strpos($errorLog, 'secret detail') === false);
pin('the order stays pending', Order::STATUS_PENDING, OrderStore::find($broken->getId())->getStatus());

harness_section('Orders: setMeta');

$metaOrder = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 1, array('session' => 'cs_1'));
$metaOf    = static fn (): array => OrderStore::find($metaOrder->getId())->getMeta();
check('a value is set', OrderStore::setMeta($metaOrder->getId(), 'stripe_livemode', false));
pin('it reads back typed, other keys kept', array('session' => 'cs_1', 'stripe_livemode' => false), $metaOf());
check('a value is overwritten', OrderStore::setMeta($metaOrder->getId(), 'stripe_livemode', true));
pin('the new value reads back', true, OrderStore::find($metaOrder->getId())->meta('stripe_livemode'));
check('setting the same value again reports success', OrderStore::setMeta($metaOrder->getId(), 'stripe_livemode', true));
check('null removes the key', OrderStore::setMeta($metaOrder->getId(), 'stripe_livemode', null));
pin('only the other key is left', array('session' => 'cs_1'), $metaOf());
check('a reserved key is refused', !OrderStore::setMeta($metaOrder->getId(), OrderStore::REFUND_REQUESTED, 'x'));
check('any key starting with _ is refused', !OrderStore::setMeta($metaOrder->getId(), '_mine', 1));
check('an upper-case key is refused', !OrderStore::setMeta($metaOrder->getId(), 'Mode', 1));
check('a key over 64 characters is refused', !OrderStore::setMeta($metaOrder->getId(), str_repeat('k', 65), 1));
check('an array value is refused', !OrderStore::setMeta($metaOrder->getId(), 'list', array(1)));
check('a string over 255 characters is refused', !OrderStore::setMeta($metaOrder->getId(), 'long', str_repeat('x', 256)));
check('a missing order is refused', !OrderStore::setMeta(999999, 'k', 1));
pin('nothing refused was written', array('session' => 'cs_1'), $metaOf());

$markedOrder = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 1);
Billing::markPaid($markedOrder, 'ext_marked');
OrderStore::markRefundRequested($markedOrder->getId());
$mark = OrderStore::find($markedOrder->getId())->meta(OrderStore::REFUND_REQUESTED);
check('a plugin key can sit beside the sent mark', OrderStore::setMeta($markedOrder->getId(), 'stripe_livemode', false));
pin('the sent mark is untouched', $mark, OrderStore::find($markedOrder->getId())->meta(OrderStore::REFUND_REQUESTED));
check('removing a plugin key keeps the sent mark', OrderStore::setMeta($markedOrder->getId(), 'stripe_livemode', null)
    && OrderStore::find($markedOrder->getId())->meta(OrderStore::REFUND_REQUESTED) === $mark);

harness_section('Billing: dashboardUrl');

$linked = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 1);
pin('no link when the gateway gives none', null, Billing::dashboardUrl($linked));
$linkTo = static function (?string $url) use ($refundy, $linked): ?string {
    $refundy->onDashboard = static fn (Order $o): ?string => $url;

    return Billing::dashboardUrl($linked);
};
pin('an https link is kept', 'https://dashboard.example.test/p/1?x=1', $linkTo('https://dashboard.example.test/p/1?x=1'));
pin('upper-case HTTPS is kept', 'HTTPS://dashboard.example.test/', $linkTo('HTTPS://dashboard.example.test/'));
pin('http is refused', null, $linkTo('http://dashboard.example.test/'));
pin('javascript: is refused', null, $linkTo('javascript:alert(1)'));
pin('a relative link is refused', null, $linkTo('/p/1'));
pin('a scheme-relative link is refused', null, $linkTo('//dashboard.example.test/'));
pin('https without a host is refused', null, $linkTo('https:///p/1'));
pin('a link with a space is refused', null, $linkTo('https://dashboard.example.test/a b'));
pin('a link with a newline is refused', null, $linkTo("https://dashboard.example.test/\n"));
$refundy->onDashboard = static function (Order $o): ?string {
    throw new RuntimeException('secret detail', 7);
};
$logged = ini_get('error_log');
ini_set('error_log', tempnam(sys_get_temp_dir(), 'billing-dashboard'));
$link     = Billing::dashboardUrl($linked);
$errorLog = (string) file_get_contents(ini_get('error_log'));
@unlink(ini_get('error_log'));
ini_set('error_log', (string) $logged);
pin('a throwing gateway gives no link', null, $link);
check('the class and code are logged', strpos($errorLog, 'RuntimeException (code 7)') !== false);
check('the message is not logged', strpos($errorLog, 'secret detail') === false);
$refundy->onDashboard = null;
pin('a gateway without the interface gives no link', null, Billing::dashboardUrl(OrderStore::create($userId, 'fake', 1, 'USD', 1)));

/* ----------------------------------------------------------------------------
 * Reopening a failed order. OrderStore::settle()/Billing::markPaid() are guarded on
 * pending by default -- a gateway retrying its own callback must never revive a
 * failed order by itself. The admin "mark paid" escape hatch is the one caller
 * allowed to widen that guard, because a person is confirming a real retried
 * payment, not a replayed webhook doing it unattended.
 * ------------------------------------------------------------------------- */
harness_section('Orders/Billing: a failed order can be reopened, but only by the admin path');

$failedOrder = OrderStore::create($userId, 'fake', 1_000_000, 'USD', 15);
check('an order can be moved to failed', OrderStore::settle($failedOrder->getId(), Order::STATUS_FAILED, 'ext_6'));
pin('the order reads back as failed', Order::STATUS_FAILED, OrderStore::find($failedOrder->getId())->getStatus());

$balanceBeforeReopen = Wallet::balance($userId);
check(
    'the default (gateway-callback) guard refuses to settle a failed order',
    Billing::markPaid(OrderStore::find($failedOrder->getId()), 'ext_6') === false
);
pin('a refused reopen mints nothing', $balanceBeforeReopen, Wallet::balance($userId));
pin(
    'a refused reopen leaves the order failed',
    Order::STATUS_FAILED,
    OrderStore::find($failedOrder->getId())->getStatus()
);

check(
    'the admin-only path (allowFailed) settles a failed order',
    Billing::markPaid(OrderStore::find($failedOrder->getId()), 'ext_6', true) === true
);
pin('reopening a failed order mints its credits', $balanceBeforeReopen + 15, Wallet::balance($userId));
pin('the reopened order reads back as paid', Order::STATUS_PAID, OrderStore::find($failedOrder->getId())->getStatus());

check(
    'reopening an order that is already paid reports no change even with allowFailed',
    Billing::markPaid(OrderStore::find($failedOrder->getId()), 'ext_6', true) === false
);
pin('a repeated reopen mints nothing further', $balanceBeforeReopen + 15, Wallet::balance($userId));

/* ----------------------------------------------------------------------------
 * Registry.
 * ------------------------------------------------------------------------- */
harness_section('PaymentGatewayRegistry');

pin('registered gateway is retrievable', 'fake', PaymentGatewayRegistry::getInstance()->get('fake')->getId());
pin('unknown gateway reads as null', null, PaymentGatewayRegistry::getInstance()->get('missing'));

$unconfigured = new FakeGateway('halfdone', false);
PaymentGatewayRegistry::getInstance()->register($unconfigured);
check(
    'an unconfigured gateway is listed but not offered',
    isset(PaymentGatewayRegistry::getInstance()->all()['halfdone'])
    && !isset(PaymentGatewayRegistry::getInstance()->available()['halfdone'])
);
check(
    'available() filters by currency',
    isset(PaymentGatewayRegistry::getInstance()->available('USD')['fake'])
    && !isset(PaymentGatewayRegistry::getInstance()->available('GBP')['fake'])
);

check('valid ids are lower-case slugs', PaymentGatewayRegistry::isValidId('stripe-eu.v2'));
check('ids reject upper case', !PaymentGatewayRegistry::isValidId('Stripe'));
check('ids reject spaces', !PaymentGatewayRegistry::isValidId('my gateway'));

$threw = false;
try {
    PaymentGatewayRegistry::getInstance()->register(new FakeGateway('Bad Id'));
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

// item_premium_on goes through Billing::deferHook() and fires only once spend()'s
// transaction has committed. This witness pins that it fires exactly once with the
// item id, AND that no transaction is open when it runs -- firing from inside
// apply() would leave osc_db_in_transaction() true and hold the wallet row's lock.
$premiumHookFired = array();
$premiumHookInTxn = array();
osc_add_hook('item_premium_on', static function ($itemId) use (&$premiumHookFired, &$premiumHookInTxn) {
    $premiumHookFired[] = $itemId;
    $premiumHookInTxn[] = osc_db_in_transaction();
});

check('listing.premium is unregistered while disabled', FeatureRegistry::getInstance()->get('listing.premium') === null);
check(
    'spending on listing.premium while disabled fails',
    Billing::spend($premiumUserId, 'listing.premium', array('itemId' => $premiumItemId, 'ref_type' => 'item', 'ref_id' => $premiumItemId)) === false
);
pin('a refused spend fires no item_premium_on', 0, count($premiumHookFired));

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

check('listing.premium registers once its preference is on', FeatureRegistry::getInstance()->get('listing.premium') !== null);
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
pin('a successful spend fires item_premium_on exactly once', array($premiumItemId), $premiumHookFired);
pin(
    'item_premium_on fired with no transaction open -- after commit, not from inside apply()',
    array(false),
    $premiumHookInTxn
);
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
pin('a spend refused by the master switch still fires no item_premium_on', array($premiumItemId), $premiumHookFired);

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

check('a fresh quantity grant creates a row', EntitlementStore::grant($entUserId, 'test.qty', 5, null));
pin('the fresh grant reads back as its own quantity', 5, EntitlementStore::quantity($entUserId, 'test.qty'));

check('granting the same feature again succeeds', EntitlementStore::grant($entUserId, 'test.qty', 3, null));
pin('a quantity grant merges into the existing row', 8, EntitlementStore::quantity($entUserId, 'test.qty'));
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
    EntitlementStore::grant($unlimitedGrantUserId, 'test.qty.unlimited', 5, null)
);
pin(
    'a quantity grant onto an unlimited row leaves it unlimited',
    -1,
    EntitlementStore::quantity($unlimitedGrantUserId, 'test.qty.unlimited')
);
pin('the grant onto the unlimited row still writes exactly one row', 1, $entCount($unlimitedGrantUserId, 'test.qty.unlimited'));

/* A duration grant while time remains has to compound onto that remaining time,
 * not discard it -- otherwise buying 30 more days while 10 remain would be a
 * downgrade to 30 instead of the 40 the buyer paid for. */
check('a fresh duration grant creates a row', EntitlementStore::grant($entUserId, 'test.dur', null, 10));
$firstExpiration = $expirationOf($entUserId, 'test.dur');
check('the fresh grant has an expiration', $firstExpiration !== null);

check('extending the same feature again succeeds', EntitlementStore::grant($entUserId, 'test.dur', null, 5));
pin(
    'a duration grant extends from the current expiry, not from now',
    date('Y-m-d H:i:s', strtotime($firstExpiration) + 5 * 86400),
    $expirationOf($entUserId, 'test.dur')
);
pin('the duration extension still writes exactly one row, not a second', 1, $entCount($entUserId, 'test.dur'));

/* ----------------------------------------------------------------------------
 * Calendar-correct day arithmetic. EntitlementStore::addDays() and
 * ItemUpgradeStore::nextExpiration() must add calendar days, not days * 86400 raw
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
$addDays = new ReflectionMethod(EntitlementStore::class, 'addDays');
if (PHP_VERSION_ID < 80100) {
    $addDays->setAccessible(true);
}
pin(
    'EntitlementStore::addDays() preserves wall-clock time across a DST boundary',
    '2024-03-10 10:00:00',
    $addDays->invoke(null, '2024-02-09 10:00:00', 30)
);

$nextExpiration = new ReflectionMethod(ItemUpgradeStore::class, 'nextExpiration');
if (PHP_VERSION_ID < 80100) {
    $nextExpiration->setAccessible(true);
}
pin(
    'ItemUpgradeStore::nextExpiration() days offset preserves wall-clock time across the same boundary',
    '2024-03-10 10:00:00',
    $nextExpiration->invoke(null, null, 30, null, '2024-02-09 10:00:00')
);

// Hours are the deliberate exception: a 24-hour bump cooldown means 24 real
// elapsed hours, not "this time tomorrow", so it must NOT track the wall clock
// across the same boundary -- 24 hours after 2024-03-09 10:00 is 11:00 local
// the next day, once the clocks have sprung forward in between.
pin(
    'ItemUpgradeStore::nextExpiration() hours offset stays literal elapsed seconds across the same boundary',
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

EntitlementStore::grant($entUserId, 'test.single', 1, null);
check('consuming the only unit succeeds', EntitlementStore::consume($entUserId, 'test.single', 1) === true);
check('consuming again with nothing left fails', EntitlementStore::consume($entUserId, 'test.single', 1) === false);
pin('the exhausted entitlement reads back as zero', 0, EntitlementStore::quantity($entUserId, 'test.single'));

$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_user_entitlement'
    . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
    . ' VALUES (' . $entUserId . ", 'test.expired', 5, '"
    . date('Y-m-d H:i:s', time() - 3600) . "', 'grant', NOW())"
);
check(
    'consuming an expired entitlement fails despite a positive quantity',
    EntitlementStore::consume($entUserId, 'test.expired', 1) === false
);

/* ----------------------------------------------------------------------------
 * Entitlements: canPublish()'s optional $withinFreeQuota parameter.
 * ItemActions::add() computes the same COUNT withinFreeQuota() would otherwise
 * run again internally, and hands the answer back in rather than paying for it
 * twice per post -- an explicit value must win over whatever a fresh
 * computation would say, in both directions.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: canPublish() $withinFreeQuota override');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();

$overrideUserId = seed_user($admin, 'canpublishoverride', 'canpublishoverride@example.test');
seed_item($admin, $categoryId, $overrideUserId, 'Fills the one free slot');
check(
    'over quota with nothing bought, publishing is refused with no override',
    EntitlementStore::canPublish($overrideUserId) === false
);
check(
    'the same user reads as allowed once withinFreeQuota is passed in as true',
    EntitlementStore::canPublish($overrideUserId, array(), true) === true
);
check(
    'passing the computed false back in agrees with the no-override refusal',
    EntitlementStore::canPublish($overrideUserId, array(), false) === false
);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * Billing::spend(). The debit and the feature's effect have to move together:
 * insufficient credit must touch neither, and a feature that reports failure
 * must give its credits back rather than keep them.
 * ------------------------------------------------------------------------- */
harness_section('Billing: spend');

$spendUserId = seed_user($admin, 'spender', 'spender@example.test');

FeatureRegistry::getInstance()->register('test.spend.costly', array(
    'label'    => 'Too expensive to afford',
    'consumes' => Feature::CONSUMES_QUANTITY,
    'price'    => 999999,
    'apply'    => static function (int $userId) {
        // Only reachable if the debit went through despite insufficient funds --
        // its own grant must never show up either.
        EntitlementStore::grant($userId, 'test.spend.costly.granted', 1, null);

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
    EntitlementStore::quantity($spendUserId, 'test.spend.costly.granted')
);

Wallet::credit($spendUserId, 100, Wallet::REASON_GRANT);

FeatureRegistry::getInstance()->register('test.spend.rejected', array(
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

$packageId = PackageStore::create(array(
    's_name'     => 'Test bundle',
    'i_amount'   => 5_000_000,
    's_currency' => 'USD',
    'i_credits'  => 250,
));
$package = PackageStore::find($packageId);

$packageOrder = OrderStore::create(
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

check('a fresh grant creates a row', ItemUpgradeStore::grant($grantItemId, 'test.upgrade', 10, null));
pin('the fresh grant writes exactly one row', 1, $upgradeItemCount($grantItemId, 'test.upgrade'));
$firstUpgradeExpiration = $upgradeExpiration($grantItemId, 'test.upgrade');
check('the fresh grant has an expiration', $firstUpgradeExpiration !== null);

check('granting the same upgrade again succeeds', ItemUpgradeStore::grant($grantItemId, 'test.upgrade', 5, null));
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

ItemUpgradeStore::grant($readItemId, 'test.live', 10, null);
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

check('a live row is held', ItemUpgradeStore::has($readItemId, 'test.live'));
check('a permanent row is held', ItemUpgradeStore::has($readItemId, 'test.permanent'));
check('a lapsed row is not held', ItemUpgradeStore::has($readItemId, 'test.lapsed') === false);

$active = ItemUpgradeStore::active($readItemId);
check('active() includes the live upgrade', in_array('test.live', $active, true));
check('active() includes the permanent upgrade', in_array('test.permanent', $active, true));
check('active() excludes the lapsed upgrade', !in_array('test.lapsed', $active, true));

pin(
    'expiresAt() returns the raw value even for a lapsed row',
    $upgradeExpiration($readItemId, 'test.lapsed'),
    ItemUpgradeStore::expiresAt($readItemId, 'test.lapsed')
);
pin('expiresAt() reads null for an upgrade the item never had', null, ItemUpgradeStore::expiresAt($readItemId, 'test.never'));

/* ----------------------------------------------------------------------------
 * ItemUpgrades: purge. Only a lapsed row is fair game -- a live one and a
 * permanent one both have to survive the sweep untouched.
 * ------------------------------------------------------------------------- */
harness_section('ItemUpgrades: purge');

$purged = ItemUpgradeStore::purge();
check('purge removes at least the one lapsed row seeded above', $purged >= 1);
pin('the lapsed row is gone', 0, $upgradeItemCount($readItemId, 'test.lapsed'));
pin('the live row survives the sweep', 1, $upgradeItemCount($readItemId, 'test.live'));
pin('the permanent row survives the sweep', 1, $upgradeItemCount($readItemId, 'test.permanent'));
pin('a second purge finds nothing left to remove', 0, ItemUpgradeStore::purge());

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

ItemUpgradeStore::grant($primeItemA, 'test.prime', 10, null);
ItemUpgradeStore::grant($primeItemB, 'test.prime', 10, null);
ItemUpgradeStore::grant($primeItemC, 'test.prime', 10, null);

ItemUpgradeStore::prime(array($primeItemA, $primeItemB));

check('a primed item with a row reports it held', ItemUpgradeStore::has($primeItemA, 'test.prime'));
check('a second primed item with a row reports it held', ItemUpgradeStore::has($primeItemB, 'test.prime'));

$admin->query(
    'DELETE FROM ' . DB_TABLE_PREFIX . "t_item_upgrade WHERE s_upgrade = 'test.prime'"
    . ' AND fk_i_item_id IN (' . $primeItemA . ', ' . $primeItemB . ', ' . $primeItemC . ')'
);

check(
    'a primed item still reads its upgrade after the row is deleted underneath it -- it read the cache, not a fresh query',
    ItemUpgradeStore::has($primeItemA, 'test.prime') === true
);
check('the second primed item is unaffected too', ItemUpgradeStore::has($primeItemB, 'test.prime') === true);
check(
    'an item never primed reflects the delete immediately -- its read was not cached',
    ItemUpgradeStore::has($primeItemC, 'test.prime') === false
);

/* The single-item fallback (an item nothing ever primed) has to memoize its own
 * first read too, so a second helper called on the same item right after the
 * first costs nothing further -- proved the same way. */
$fallbackItemId = seed_item($admin, $categoryId, $primeUserId, 'Fallback memoized');
ItemUpgradeStore::grant($fallbackItemId, 'test.fallback', 10, null);

check('a fresh (unprimed) read finds the row', ItemUpgradeStore::has($fallbackItemId, 'test.fallback'));

$admin->query(
    'DELETE FROM ' . DB_TABLE_PREFIX . "t_item_upgrade WHERE s_upgrade = 'test.fallback' AND fk_i_item_id = "
    . $fallbackItemId
);

check(
    'a second call for the same never-primed item is memoized -- it still reads held, not the row just deleted',
    ItemUpgradeStore::has($fallbackItemId, 'test.fallback') === true
);

/* prime() has to populate the cache for every id given in one call, including
 * an id with no rows at all -- "primed, holds nothing" must not fall through
 * to a fresh query just because there was nothing to remember. */
$emptyItemId = seed_item($admin, $categoryId, $primeUserId, 'No upgrades at all');
ItemUpgradeStore::prime(array($emptyItemId));
$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_upgrade'
    . ' (fk_i_item_id, s_upgrade, dt_expiration, dt_date)'
    . ' VALUES (' . $emptyItemId . ", 'test.sneaked_in', NULL, NOW())"
);
check(
    'an item primed with no rows stays "no upgrades" even if a row appears afterward -- it read the cache, not the table',
    ItemUpgradeStore::active($emptyItemId) === array()
);

/* A write has to invalidate the cache entry it affects, or a purchase made
 * right after a primed read would be invisible to the very next read in the
 * same request -- the cache must never paper over the seller's own purchase. */
$writeInvalidatesId = seed_item($admin, $categoryId, $primeUserId, 'Write invalidates cache');
ItemUpgradeStore::prime(array($writeInvalidatesId));
check('primed with nothing before the grant', ItemUpgradeStore::has($writeInvalidatesId, 'test.invalidate') === false);
ItemUpgradeStore::grant($writeInvalidatesId, 'test.invalidate', 10, null);
check(
    'a grant right after a primed read is visible on the very next read, not stale',
    ItemUpgradeStore::has($writeInvalidatesId, 'test.invalidate') === true
);

/* ----------------------------------------------------------------------------
 * osc_prime_item_upgrades(): the public helper. Accepts item rows and bare ids
 * in the same call, since a theme has whichever is already to hand. It primes with
 * billing off too, since themes read upgrades on every card either way.
 * ------------------------------------------------------------------------- */
harness_section('osc_prime_item_upgrades(): rows or ids, billing on or off');

$helperItemId  = seed_item($admin, $categoryId, $primeUserId, 'Helper primed by id');
$helperItemId2 = seed_item($admin, $categoryId, $primeUserId, 'Helper primed by row');
ItemUpgradeStore::grant($helperItemId, 'test.helper', 10, null);
ItemUpgradeStore::grant($helperItemId2, 'test.helper', 10, null);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
osc_prime_item_upgrades(array($helperItemId));
$admin->query(
    'DELETE FROM ' . DB_TABLE_PREFIX . "t_item_upgrade WHERE s_upgrade = 'test.helper' AND fk_i_item_id = "
    . $helperItemId
);
check(
    'osc_prime_item_upgrades() primes while billing is off too -- it read the cache, not the table',
    ItemUpgradeStore::has($helperItemId, 'test.helper') === true
);
ItemUpgradeStore::grant($helperItemId, 'test.helper', 10, null); // restore for the next check

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
check('a bare id in the array is primed', ItemUpgradeStore::has($helperItemId, 'test.helper') === true);
check(
    'an item row (pk_i_id) in the same array is primed too',
    ItemUpgradeStore::has($helperItemId2, 'test.helper') === true
);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

// Fixtures for the ItemActions::add() tests below: sanitisation/validation helpers,
// a real (non-root) category, an item-data builder and a warnings-capturing harness,
// all reused by more than one section from here on.

// ItemActions::add() reaches sanitisation, validation and Item::updateExpirationDate()
// -- none of hValidate.php/hSanitize.php/hSecurity.php
// is pulled in by the requires above, unlike tests/models/item.php's stand-ins, because
// nothing else in this file needed them until now.
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php'; // Category::__construct() -> osc_current_user_locale()
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';  // Category::__construct() -> toTree() -> the category cache
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';  // Log::insertLog() -> osc_logged_user_id()

// osc_validate_category() requires a non-root category (or osc_selectable_parent_categories()),
// so the fixture needs a parent, unlike $categoryId above.
$chokeParentCat = seed_category($admin, 'Choke parent');
$chokeCat       = seed_category($admin, 'Choke child', $chokeParentCat);

$makeChokeItemData = static function (int $userId, int $catId, string $title): array {
    return array(
        'title'         => array('en_US' => $title),
        'description'   => array('en_US' => $title . ' has a description long enough to pass validation.'),
        'catId'         => $catId,
        'price'         => 10,
        'currency'      => 'USD',
        'contactName'   => 'Choke Tester',
        'contactEmail'  => 'choke@example.test',
        'contactPhone'  => '',
        'cityArea'      => '',
        'address'       => '',
        'countryId'     => 'US',
        'countryName'   => 'United States',
        'regionId'      => null,
        'regionName'    => '',
        'cityId'        => null,
        'cityName'      => '',
        'd_coord_lat'   => null,
        'd_coord_long'  => null,
        's_zip'         => '',
        'photos'        => array(),
        'showEmail'     => 1,
        'active'        => 'ACTIVE',
        'userId'        => $userId,
        's_ip'          => '127.0.0.1',
        // A digit string, not a date: Item::updateExpirationDate() reads "0" as
        // never-expires, the same convention a category with no expiration ceiling uses.
        'dt_expiration' => '0',
    );
};

$chokeWarnings   = array();
$captureWarnings = static function () use (&$chokeWarnings): void {
    $chokeWarnings = array();
    set_error_handler(static function (int $errno, string $errstr) use (&$chokeWarnings): bool {
        if ($errno === E_USER_WARNING) {
            $chokeWarnings[] = $errstr;
        }

        return true;
    });
};

/* ----------------------------------------------------------------------------
 * Entitlements: a listing occupies one of the seller's slots from the moment it
 * publishes until it expires or is deleted. Pending moderation (b_active = 0)
 * and admin-disabled (b_enabled = 0) listings still occupy one -- the
 * surprising half of the rule, and deliberate: otherwise a seller could queue
 * an unlimited number of ads awaiting approval, each free because none of them
 * "count" yet, and flood the site the moment every one is approved at once.
 * Charging for the slot at creation, not at visibility, is what makes that
 * impossible. Expiry is the one thing that DOES free a slot for free, with no
 * delete and no sweep, because EntitlementStore::liveListings() simply stops
 * counting a row the instant its own expiry test says so.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: slot quota -- occupancy, expiry, deletion');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();

$slotUserId = seed_user($admin, 'slotuser', 'slotuser@example.test');
pin('a fresh seller with no listings occupies zero slots', 0, EntitlementStore::liveListings($slotUserId));
check('under the free slot, publishing is allowed', EntitlementStore::canPublish($slotUserId));

$slotItemId = seed_item($admin, $categoryId, $slotUserId, 'Occupies the one free slot');
pin('a published listing occupies one slot', 1, EntitlementStore::liveListings($slotUserId));
check('the free slot is now taken, and nothing was bought', EntitlementStore::canPublish($slotUserId) === false);

Item::getInstance()->deleteByPrimaryKey($slotItemId);
pin('deleting the listing frees its slot', 0, EntitlementStore::liveListings($slotUserId));
check('the freed slot allows publishing again', EntitlementStore::canPublish($slotUserId));

$expiringItemId = seed_item($admin, $categoryId, $slotUserId, 'Occupies the slot, then expires');
pin('a second published listing occupies the slot again', 1, EntitlementStore::liveListings($slotUserId));

$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 1 DAY)'
    . ' WHERE pk_i_id = ' . $expiringItemId
);
pin('letting the listing expire frees its slot too, with no delete and no sweep', 0, EntitlementStore::liveListings($slotUserId));
check('the expired listing\'s slot is available again', EntitlementStore::canPublish($slotUserId));

$pendingItemId = seed_item($admin, $categoryId, $slotUserId, 'Pending moderation', 19.50, 0, 1);
pin('a pending (not yet active) listing still occupies a slot', 1, EntitlementStore::liveListings($slotUserId));
Item::getInstance()->deleteByPrimaryKey($pendingItemId);

$disabledItemId = seed_item($admin, $categoryId, $slotUserId, 'Admin-disabled', 19.50, 1, 0);
pin('an admin-disabled listing still occupies a slot', 1, EntitlementStore::liveListings($slotUserId));
Item::getInstance()->deleteByPrimaryKey($disabledItemId);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * Entitlements: a listing.slot capacity entitlement raises the ceiling
 * (osc_billing_free_live_listings() + capacity()), and an unlimited one (NULL
 * quantity, read back as -1) means unlimited -- never folded into the
 * arithmetic sum, or unlimited would read as some other finite ceiling.
 * ------------------------------------------------------------------------- */
harness_section('Entitlements: a listing.slot entitlement raises the ceiling');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();

$capacityUserId = seed_user($admin, 'slotcapacity', 'slotcapacity@example.test');
seed_item($admin, $categoryId, $capacityUserId, 'Fills the one free slot');
check('with the one free slot filled, publishing is refused', EntitlementStore::canPublish($capacityUserId) === false);

EntitlementStore::grant($capacityUserId, 'listing.slot', 2, null);
pin('capacity() reads the bought entitlement back', 2, EntitlementStore::capacity($capacityUserId, 'listing.slot', 0));
check(
    'a listing.slot entitlement (+2) raises the ceiling to 3, so publishing is allowed again',
    EntitlementStore::canPublish($capacityUserId)
);

seed_item($admin, $categoryId, $capacityUserId, 'Second, using the bought slot');
seed_item($admin, $categoryId, $capacityUserId, 'Third, using the bought slot');
check('three live listings exactly fill 1 free + 2 bought', EntitlementStore::canPublish($capacityUserId) === false);

// billing_free_live_listings is raised to 3 (rather than left at 1) specifically so
// that naively folding capacity()'s -1 into the arithmetic sum (3 + -1 = 2, a real,
// finite ceiling) would visibly disagree with the pin below once 3 listings are live
// -- at free=1 the naive sum would land at 0 and the <=0 "unlimited" fallback would
// mask the bug by coincidence, proving nothing.
$unlimitedSlotUserId = seed_user($admin, 'slotunlimited', 'slotunlimited@example.test');
osc_set_preference('billing_free_live_listings', '3', 'osclass', 'INTEGER');
EntitlementStore::grant($unlimitedSlotUserId, 'listing.slot', null, null);
pin('capacity() reads an unlimited listing.slot entitlement as -1', -1, EntitlementStore::capacity($unlimitedSlotUserId, 'listing.slot', 0));
seed_item($admin, $categoryId, $unlimitedSlotUserId, 'First of many, unlimited slots');
seed_item($admin, $categoryId, $unlimitedSlotUserId, 'Second of many, unlimited slots');
seed_item($admin, $categoryId, $unlimitedSlotUserId, 'Third of many, unlimited slots');
check(
    'an unlimited (-1) listing.slot entitlement means unlimited, not "less than everything"'
    . ' (3 free + unlimited must allow a 4th, not stop at 3 + (-1) = 2)',
    EntitlementStore::canPublish($unlimitedSlotUserId)
);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * The theme-facing quota helpers. These are what a theme shows a seller, so they
 * are pinned against the same fixtures the gate above is measured on -- a number
 * a seller is told that disagrees with the one they are held to is the whole bug
 * this set exists to catch.
 * ------------------------------------------------------------------------- */
harness_section('hBilling: listing-quota helpers a theme can read');

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
$quotaUserId = seed_user($admin, 'quotaread', 'quotaread@example.test');
pin('billing off -> unlimited (-1), whatever the cap says', -1, osc_user_listing_limit($quotaUserId));
pin('billing off -> nothing counted as used', 0, osc_user_listings_used($quotaUserId));
pin('billing off -> unlimited remaining', -1, osc_user_listings_remaining($quotaUserId));
check('billing off -> publishing is allowed', osc_user_can_publish($quotaUserId));

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_live_listings', '2', 'osclass', 'INTEGER');
osc_reset_preferences();

pin('a cap of 2 reads back as 2', 2, osc_user_listing_limit($quotaUserId));
pin('nothing published yet -> 0 used', 0, osc_user_listings_used($quotaUserId));
pin('...so 2 remain', 2, osc_user_listings_remaining($quotaUserId));

seed_item($admin, $categoryId, $quotaUserId, 'Quota reader, first');
pin('one live listing -> 1 used', 1, osc_user_listings_used($quotaUserId));
pin('...and 1 remaining', 1, osc_user_listings_remaining($quotaUserId));
check('still allowed to publish', osc_user_can_publish($quotaUserId));

seed_item($admin, $categoryId, $quotaUserId, 'Quota reader, second');
pin('at the ceiling -> 0 remaining', 0, osc_user_listings_remaining($quotaUserId));
check('...and publishing is refused, the same answer the post route enforces', osc_user_can_publish($quotaUserId) === false);
check('...which is exactly what the gate itself says', EntitlementStore::canPublish($quotaUserId) === false);

/* An unset cap is unlimited, not zero -- the reading an install that never touched
   billing must get. */
osc_set_preference('billing_free_live_listings', '0', 'osclass', 'INTEGER');
osc_reset_preferences();
pin('a cap of 0 means unlimited, not blocked', -1, osc_user_listing_limit($quotaUserId));
pin('...and unlimited remaining', -1, osc_user_listings_remaining($quotaUserId));
check('...and publishing is allowed again', osc_user_can_publish($quotaUserId));

/* Over the line reads back 0, never a negative: a seller whose cap was lowered
   under them still gets a number a theme can print. */
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();
pin('two live against a cap of 1 clamps at 0 remaining, not -1', 0, osc_user_listings_remaining($quotaUserId));

pin(
    'the limit message is the one wording, defaulting to the no-purchase remedies',
    'You are at your listing limit. Free up a listing -- delete one or let one expire -- to post again.',
    osc_listing_limit_message($quotaUserId)
);
osc_add_filter('billing_listing_limit_message', static function ($message, $userId, $item) {
    return 'Buy a slot to keep posting.';
});
pin(
    'billing_listing_limit_message lets a plugin selling slots say so instead',
    'Buy a slot to keep posting.',
    osc_listing_limit_message($quotaUserId)
);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * ItemActions::add(): publishing consumes nothing. A slot is occupied by the
 * row existing, not spent by the insert -- there is no consume-after-insert
 * path at all, and so nothing to warn about if one were to fail.
 * ------------------------------------------------------------------------- */
harness_section('ItemActions::add(): publishing consumes no entitlement quantity');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_live_listings', '5', 'osclass', 'INTEGER');
osc_reset_preferences();

$noConsumeUser = seed_user($admin, 'noconsume', 'noconsume@example.test');
EntitlementStore::grant($noConsumeUser, 'listing.slot', 3, null);

$captureWarnings();
$noConsumeAction       = new ItemActions(false);
$noConsumeAction->data = $makeChokeItemData($noConsumeUser, $chokeCat, 'Publish, nothing spent');
$noConsumeResult       = $noConsumeAction->add();
restore_error_handler();
$noConsumeWarnings = $chokeWarnings;

check('the post succeeds', $noConsumeResult === 2);
pin('the listing.slot capacity entitlement is untouched by publishing', 3, EntitlementStore::capacity($noConsumeUser, 'listing.slot', 0));
pin('no warning fired -- there is no consume() call in the publish path to fail', array(), $noConsumeWarnings);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

/* ----------------------------------------------------------------------------
 * ItemActions::add(): custom field values handed over with the data are saved.
 * An importer has no form post, so 'meta' in the data stands in for the posted one.
 * ------------------------------------------------------------------------- */
harness_section('ItemActions::add(): custom field values from the data');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php'; // handleMetaField() -> osc_field_type()

$metaFieldId = seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . "t_meta_fields (s_name, e_type, b_required, b_searchable, s_slug, i_position, s_meta)
     VALUES ('Colour', 'TEXT', 0, 0, 'colour', 0, '')",
    '',
    array()
);
seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)',
    'ii',
    array($chokeCat, $metaFieldId)
);

$metaUser             = seed_user($admin, 'metadata', 'metadata@example.test');
$metaAction           = new ItemActions(true);
$metaAction->data     = $makeChokeItemData($metaUser, $chokeCat, 'Custom field from the data');
$metaAction->data['meta'] = array($metaFieldId => 'Blue');
Params::setParam('meta', null);
$captureWarnings();
$metaAction->add();
restore_error_handler();

$metaItemId = (int)$admin->query(
    'SELECT MAX(pk_i_id) FROM ' . DB_TABLE_PREFIX . 't_item WHERE fk_i_user_id = ' . (int)$metaUser
)->fetch_row()[0];
$metaRow = $admin->query(
    'SELECT s_value FROM ' . DB_TABLE_PREFIX . 't_item_meta WHERE fk_i_item_id = ' . $metaItemId
    . ' AND fk_i_field_id = ' . (int)$metaFieldId
)->fetch_row();
check('the listing was created', $metaItemId > 0);
pin('the custom field value in the data is saved', 'Blue', $metaRow[0] ?? null);

/* ----------------------------------------------------------------------------
 * ItemActions for an importer: asImport() is admin mode that still applies the
 * listing limit and moderation, an admin edit needs no secret, and
 * prepareDataFrom() reads plain data with ownerId naming the account.
 * ------------------------------------------------------------------------- */
harness_section('ItemActions: import mode, admin edit, data in');

$itemCol = static function (int $id, string $col) use ($admin) {
    $row = $admin->query('SELECT ' . $col . ' FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $id)->fetch_row();

    return $row[0] ?? null;
};
$lastItem = static function (int $userId) use ($admin): int {
    return (int)$admin->query('SELECT MAX(pk_i_id) FROM ' . DB_TABLE_PREFIX . 't_item WHERE fk_i_user_id = ' . $userId)->fetch_row()[0];
};

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();
$fullUser = seed_user($admin, 'importfull', 'importfull@example.test');

$captureWarnings();
$plain       = new ItemActions(true);
$plain->data = $makeChokeItemData($fullUser, $chokeCat, 'Admin post over the limit');
$plainResult = $plain->add();
$import       = (new ItemActions(true))->asImport();
$import->data = $makeChokeItemData($fullUser, $chokeCat, 'Import over the limit');
$importResult = $import->add();
restore_error_handler();
pin('a plain admin post is not metered, and takes the one slot', 2, $plainResult);
check('an import is held to the owner\'s listing limit', is_string($importResult) && $importResult !== '');

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('moderate_admin_post', '1', 'osclass', 'BOOLEAN');
osc_reset_preferences();
$modUser = seed_user($admin, 'importmod', 'importmod@example.test');
$captureWarnings();
$import       = (new ItemActions(true))->asImport();
$import->data = $makeChokeItemData($modUser, $chokeCat, 'Import under moderation');
$import->add();
$modItem      = $lastItem($modUser);
$plain        = new ItemActions(true);
$plain->data  = $makeChokeItemData($modUser, $chokeCat, 'Admin post under moderation');
$plain->add();
$plainItem    = $lastItem($modUser);
restore_error_handler();
pin('an import waits for moderation', '0', $itemCol($modItem, 'b_enabled'));
pin('a plain admin post does not', '1', $itemCol($plainItem, 'b_enabled'));
osc_set_preference('moderate_admin_post', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();

$captureWarnings();
$edit = new ItemActions(true);
Params::withRequest(array(), static function () use ($edit, $plainItem, $chokeCat) {
    $edit->prepareDataFrom(array(
        'id'           => $plainItem,
        'title'        => array('en_US' => 'Edited from data'),
        'description'  => array('en_US' => 'Edited from data, with a description long enough.'),
        'catId'        => $chokeCat,
        'price'        => '99',
        'contactEmail' => 'importmod@example.test',
    ), false);
});
$edit->edit();
restore_error_handler();
pin('an admin edit from plain data saves without the secret', '99000000', $itemCol($plainItem, 'i_price'));

$seen = Params::withRequest(array(), static function () use ($modUser, $chokeCat) {
    $in = new ItemActions(true);
    $in->prepareDataFrom(array(
        'title'        => array('en_US' => 'From data'),
        'catId'        => $chokeCat,
        'contactEmail' => 'someone@example.test',
        'ownerId'      => $modUser,
        'photos'       => array('/tmp/a.jpg', 42),
        'meta'         => array(7 => 'Red'),
    ), true);

    return array($in->data['title'], (int)$in->data['userId'], $in->data['photos']['tmp_name'], $in->data['meta'], Params::getParam('title'));
});
pin('prepareDataFrom reads the plain values', array('en_US' => 'From data'), $seen[0]);
$halfId = Params::withRequest(array(), static function () {
    $in = new ItemActions(false);
    $in->prepareDataFrom(array('id' => '12.9', 'title' => array('en_US' => 'x')), false);

    return $in->data['idItem'];
});
pin('an edit id like "12.9" is read as 12, as the owner check reads it, not rounded to 13 by MySQL', 12, $halfId);
pin('ownerId names the account, whatever the contact e-mail', $modUser, $seen[1]);
pin('photos are local paths, and nothing else', array('/tmp/a.jpg'), $seen[2]);
pin('meta comes with the data', array(7 => 'Red'), $seen[3]);
pin('and the request is left as it was', '', $seen[4]);

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

// item_bumped goes through Billing::deferHook() and fires only once spend()'s
// transaction has committed. This witness pins that it fires once per successful
// spend with the item id, AND that no transaction is open when it runs (see the
// item_premium_on witness above for why that is the property that matters).
$bumpHookFired = array();
$bumpHookInTxn = array();
osc_add_hook('item_bumped', static function ($itemId) use (&$bumpHookFired, &$bumpHookInTxn) {
    $bumpHookFired[] = $itemId;
    $bumpHookInTxn[] = osc_db_in_transaction();
});

check('bump.item registers once its preference is on', FeatureRegistry::getInstance()->get('item.bump') !== null);
check(
    'spending on item.bump succeeds',
    Billing::spend($bumpUserId, 'item.bump', array('itemId' => $bumpItemId, 'ref_type' => 'item', 'ref_id' => $bumpItemId))
);
check('bump moves dt_pub_date forward', $pubDateOf($bumpItemId) > $pubDateBeforeBump);
pin('bump debits its price', $balanceBeforeBump - 5, Wallet::balance($bumpUserId));
check('the bump leaves a live cooldown row', ItemUpgradeStore::has($bumpItemId, 'item.bump'));
pin('a successful bump fires item_bumped exactly once', array($bumpItemId), $bumpHookFired);
pin(
    'item_bumped fired with no transaction open -- after commit, not from inside apply()',
    array(false),
    $bumpHookInTxn
);

// The cooldown is enforced by upgradePost()'s decideUpgrade() -- not by spend()
// itself, which has no opinion on cooldowns. upgradePost() cannot be driven directly
// (osc_csrf_check()/redirectTo() would end the test process), so this reaches its
// pure, static decision logic through reflection, the same way the DST pins above
// reach EntitlementStore::addDays() and ItemUpgradeStore::nextExpiration(). item.bump
// consumes a quantity, not a duration, so a live cooldown row must still refuse a
// repurchase outright rather than extend it -- extending would let a seller re-bump
// on demand and defeat the cooldown's entire point.
$decideUpgrade   = new ReflectionMethod(CWebBilling::class, 'decideUpgrade');
$cWebBillingRefl = new ReflectionClass(CWebBilling::class);
check(
    'the cooldown blocks while the row is live -- decideUpgrade() refuses, it does not extend',
    $decideUpgrade->invoke(null, 'item.bump', FeatureRegistry::getInstance()->get('item.bump'), array('pk_i_id' => $bumpItemId))
    === $cWebBillingRefl->getConstant('DECISION_REFUSE_HELD')
);

// A separate item, read via has() for the first time only after its cooldown row is
// fast-forwarded into the past. $bumpItemId above is already memoized, and a raw SQL
// edit of dt_expiration does not retroactively invalidate a cached read; a never-read
// item's first read still has to see the row as it stands.
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
check('the cooldown lifts once the row lapses', ItemUpgradeStore::has($lapsedBumpItemId, 'item.bump') === false);
check(
    'once the cooldown has lapsed, decideUpgrade() proceeds with a fresh bump rather than refusing',
    $decideUpgrade->invoke(null, 'item.bump', FeatureRegistry::getInstance()->get('item.bump'), array('pk_i_id' => $lapsedBumpItemId))
    === $cWebBillingRefl->getConstant('DECISION_PROCEED')
);
pin(
    'the lapse-target bump also fired item_bumped, so both successful spends announced',
    array($bumpItemId, $lapsedBumpItemId),
    $bumpHookFired
);

$balanceBeforeBogus = Wallet::balance($bumpUserId);
check(
    'bumping an item that does not exist fails',
    Billing::spend($bumpUserId, 'item.bump', array('itemId' => 999999999, 'ref_type' => 'item', 'ref_id' => 999999999)) === false
);
pin('a failed bump apply leaves the balance untouched', $balanceBeforeBogus, Wallet::balance($bumpUserId));
pin(
    'a failed bump apply fires no additional item_bumped',
    array($bumpItemId, $lapsedBumpItemId),
    $bumpHookFired
);

/* A free bump is not a way past the listing limit; a paid one is. */
harness_section('Billing: free bumps respect the listing limit');

$overUser  = seed_user($admin, 'overlimit', 'overlimit@example.test');
$overItems = array();
for ($i = 0; $i < 3; $i++) {
    $overItems[] = seed_item($admin, $categoryId, $overUser, 'Over limit ' . $i);
}
// Back-dated so a bump has a date to move.
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_pub_date = DATE_SUB(NOW(), INTERVAL 2 DAY)'
    . ' WHERE fk_i_user_id = ' . $overUser);
$overItem = static function (int $n) use ($admin, $overItems): array {
    return $admin->query('SELECT * FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $overItems[$n])->fetch_assoc();
};
$offerIds = static function (array $item): array {
    return array_column(osc_item_upgrade_offers($item), 'feature');
};
$freeBump = static function (int $userId, int $itemId): bool {
    return Billing::spend($userId, 'item.bump', array('itemId' => $itemId, 'ref_type' => 'item', 'ref_id' => $itemId));
};
\Session::getInstance()->_setEphemeral('userId', $overUser);

osc_set_preference('billing_bump_credits', '0', 'osclass', 'INTEGER');
osc_set_preference('billing_free_live_listings', '3', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_billing_bump_paused_reset();
check('at the limit (3 of 3) a free bump is allowed', EntitlementStore::withinFreeCeiling($overUser) && !osc_billing_bump_paused($overUser));
check('and offered', osc_item_can_bump($overItem(0)) && in_array('item.bump', $offerIds($overItem(0)), true));
check('and the spend goes through', $freeBump($overUser, $overItems[0]));

osc_set_preference('billing_free_live_listings', '2', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_billing_bump_paused_reset();
check('over the limit (3 of 2) free bumps are paused', osc_billing_bump_paused($overUser));
check('can_bump says no', !osc_item_can_bump($overItem(1)));
check('the Bump offer is gone', !in_array('item.bump', $offerIds($overItem(1)), true));
check('the other offers stay', $offerIds($overItem(1)) === array_values(array_diff($offerIds($overItem(1)), array('item.bump'))));
$pubBefore = $pubDateOf($overItems[1]);
check('a crafted spend is refused', !$freeBump($overUser, $overItems[1]));
pin('and the listing does not move', $pubBefore, $pubDateOf($overItems[1]));
check('the seller is told why', strpos(osc_billing_bump_paused_message($overUser), '3') !== false
    && strpos(osc_billing_bump_paused_message($overUser), '2') !== false);

EntitlementStore::grant($overUser, 'listing.slot', 1, null);
osc_billing_bump_paused_reset();
check('a bought listing slot raises the limit, so free bumps come back', !osc_billing_bump_paused($overUser)
    && osc_item_can_bump($overItem(1)));
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_user_entitlement WHERE fk_i_user_id = " . $overUser);
osc_billing_bump_paused_reset();

osc_set_preference('billing_free_live_listings', '0', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_billing_bump_paused_reset();
check('a limit of 0 means unlimited: no pause', !osc_billing_bump_paused($overUser) && osc_item_can_bump($overItem(1)));

osc_set_preference('billing_free_live_listings', '2', 'osclass', 'INTEGER');
osc_set_preference('billing_bump_credits', '5', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_billing_bump_paused_reset();
Wallet::credit($overUser, 20, Wallet::REASON_GRANT);
check('a paid bump over the limit is not paused', !osc_billing_bump_paused($overUser));
check('and is offered', in_array('item.bump', $offerIds($overItem(2)), true));
check('and the spend goes through', $freeBump($overUser, $overItems[2]));
pin('for its price', 15, Wallet::balance($overUser));
check('another user is never paused by this one', !osc_billing_bump_paused($bumpUserId));

/* The display check is remembered per user; the bump itself always looks again. */
osc_set_preference('billing_bump_credits', '0', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_billing_bump_paused_reset();
check('over the limit the display check says paused', osc_billing_bump_paused($overUser));
osc_set_preference('billing_free_live_listings', '0', 'osclass', 'INTEGER');
osc_reset_preferences();
check('the remembered answer stays for the page', osc_billing_bump_paused($overUser));
check('a fresh check sees the change', !osc_billing_bump_paused($overUser, true));
osc_billing_bump_paused_reset();
check('with no limit the display check says not paused', !osc_billing_bump_paused($overUser));
osc_set_preference('billing_free_live_listings', '2', 'osclass', 'INTEGER');
osc_reset_preferences();
check('the display check keeps that answer', !osc_billing_bump_paused($overUser));
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_item_upgrade WHERE fk_i_item_id = ' . $overItems[2]);
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_pub_date = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE pk_i_id = ' . $overItems[2]);
check('but the bump itself looks again and refuses', !$freeBump($overUser, $overItems[2]));
osc_billing_bump_paused_reset();

$manyUser  = seed_user($admin, 'manylistings', 'many@example.test');
$manyItems = array();
for ($i = 0; $i < 8; $i++) {
    $manyItems[] = $admin->query('SELECT * FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = '
        . seed_item($admin, $categoryId, $manyUser, 'Many ' . $i))->fetch_assoc();
}
\Session::getInstance()->_setEphemeral('userId', $manyUser);
harness_assert_no_n_plus_1(
    'drawing the offers for N listings costs the same queries for any N',
    static function (int $n) use ($manyItems) {
        osc_billing_bump_paused_reset();
        $rows = array_slice($manyItems, 0, $n);
        osc_prime_item_upgrades($rows);
        foreach ($rows as $row) {
            osc_item_upgrade_offers($row);
        }
        osc_billing_bump_paused_message();
    },
    1,
    8
);

\Session::getInstance()->_setEphemeral('userId', 0);
osc_set_preference('billing_free_live_listings', '0', 'osclass', 'INTEGER');
osc_set_preference('billing_bump_credits', '5', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_billing_bump_paused_reset();

osc_set_preference('billing_bump_enabled', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();
osc_billing_bump_paused_reset();

/* ----------------------------------------------------------------------------
 * CWebBilling::decideUpgrade(): the rest of the route-level decision -- a
 * duration feature held live is extended rather than refused, a permanent hold
 * has nothing to extend, and listing.premium's own already-held check reads
 * dt_premium_expiration rather than the b_premium flag, so a listing does not
 * read as premium (and un-refeaturable) for the whole window between its
 * expiry and the next hourly sweep.
 * ------------------------------------------------------------------------- */
harness_section('CWebBilling::decideUpgrade(): extend a live duration, refuse a permanent one');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_highlight_enabled', '1', 'osclass', 'BOOLEAN');
osc_set_preference('billing_premium_enabled', '1', 'osclass', 'BOOLEAN');
osc_reset_preferences();
osc_register_billing_item_upgrades();
osc_register_billing_premium();

$decideUser        = seed_user($admin, 'decideuser', 'decideuser@example.test');
$highlightFeature  = FeatureRegistry::getInstance()->get('item.highlight');
$premiumFeature    = FeatureRegistry::getInstance()->get('listing.premium');

$liveHighlightItem = seed_item($admin, $categoryId, $decideUser, 'Live highlight');
ItemUpgradeStore::grant($liveHighlightItem, 'item.highlight', 10, null);
check(
    'a live duration hold (item.highlight) is extended, not refused',
    $decideUpgrade->invoke(null, 'item.highlight', $highlightFeature, array('pk_i_id' => $liveHighlightItem))
    === $cWebBillingRefl->getConstant('DECISION_EXTEND')
);

$permanentHighlightItem = seed_item($admin, $categoryId, $decideUser, 'Permanent highlight');
$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_upgrade'
    . ' (fk_i_item_id, s_upgrade, dt_expiration, dt_date)'
    . ' VALUES (' . $permanentHighlightItem . ", 'item.highlight', NULL, NOW())"
);
check(
    'a permanent hold has nothing to extend, so decideUpgrade() refuses it',
    $decideUpgrade->invoke(null, 'item.highlight', $highlightFeature, array('pk_i_id' => $permanentHighlightItem))
    === $cWebBillingRefl->getConstant('DECISION_REFUSE_PERMANENT')
);

$freshHighlightItem = seed_item($admin, $categoryId, $decideUser, 'No highlight yet');
check(
    'an item that never held the feature at all proceeds as a fresh grant',
    $decideUpgrade->invoke(null, 'item.highlight', $highlightFeature, array('pk_i_id' => $freshHighlightItem))
    === $cWebBillingRefl->getConstant('DECISION_PROCEED')
);

/* listing.premium lives on t_item's own columns rather than t_item_upgrade --
 * this is the fix for the bug in the "already held" check reading the b_premium
 * flag instead of dt_premium_expiration. */
$livePremiumItem = seed_item($admin, $categoryId, $decideUser, 'Live premium');
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item SET b_premium = 1, dt_premium_expiration = '
    . "'" . date('Y-m-d H:i:s', time() + 86400) . "' WHERE pk_i_id = " . $livePremiumItem
);
check(
    'a live premium spot is extended, not refused',
    $decideUpgrade->invoke(
        null,
        'listing.premium',
        $premiumFeature,
        array('pk_i_id' => $livePremiumItem, 'b_premium' => 1, 'dt_premium_expiration' => date('Y-m-d H:i:s', time() + 86400))
    ) === $cWebBillingRefl->getConstant('DECISION_EXTEND')
);

$permanentPremiumItem = seed_item($admin, $categoryId, $decideUser, 'Permanent premium (decide)');
check(
    'a permanent (admin-granted, no expiration) premium spot has nothing to extend',
    $decideUpgrade->invoke(
        null,
        'listing.premium',
        $premiumFeature,
        array('pk_i_id' => $permanentPremiumItem, 'b_premium' => 1, 'dt_premium_expiration' => null)
    ) === $cWebBillingRefl->getConstant('DECISION_REFUSE_PERMANENT')
);

/* The bug this phase fixes: b_premium stays 1 from the moment a time-limited
 * premium spot expires until the next hourly sweep runs (Premium::expire()).
 * Reading that flag alone would refuse to re-feature the listing for that
 * entire window; reading dt_premium_expiration instead sees it has already
 * lapsed and treats the listing as available again. */
$lapsedPremiumItem = seed_item($admin, $categoryId, $decideUser, 'Lapsed premium, not yet swept');
check(
    'a b_premium flag left stale by an unswept expiry does not block re-featuring',
    $decideUpgrade->invoke(
        null,
        'listing.premium',
        $premiumFeature,
        array('pk_i_id' => $lapsedPremiumItem, 'b_premium' => 1, 'dt_premium_expiration' => date('Y-m-d H:i:s', time() - 3600))
    ) === $cWebBillingRefl->getConstant('DECISION_PROCEED')
);

osc_set_preference('billing_highlight_enabled', '0', 'osclass', 'BOOLEAN');
osc_set_preference('billing_premium_enabled', '0', 'osclass', 'BOOLEAN');
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

FeatureRegistry::getInstance()->register('test.price.witness', array(
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

FeatureRegistry::getInstance()->register('test.duration.witness', array(
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
    FeatureRegistry::getInstance()->get('test.duration.witness')->duration($durationUserId)
);

/* A plugin calling apply() directly still has to work -- it never goes through
 * spend(), so $ctx carries no 'days' key at all, and the witness above simply
 * records whatever it was given. */
$capturedDays = 'not called';
FeatureRegistry::getInstance()->get('test.duration.witness')->apply($durationUserId, array());
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
FeatureRegistry::getInstance()->get('item.highlight')->apply($userId, array('itemId' => $fallbackItemId));
$afterFallback = time();

$highlightExpiresAt = ItemUpgradeStore::expiresAt($fallbackItemId, 'item.highlight');
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

pin('capacity with no entitlement reads the default', 7, EntitlementStore::capacity($capUserId, 'test.capacity', 7));

EntitlementStore::grant($capUserId, 'test.capacity', 15, null);
pin('capacity reads the granted quantity', 15, EntitlementStore::capacity($capUserId, 'test.capacity', 7));

$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_user_entitlement'
    . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
    . ' VALUES (' . $capUserId . ", 'test.capacity.unlimited', NULL, NULL, 'grant', NOW())"
);
pin('capacity reads -1 for an unlimited row', -1, EntitlementStore::capacity($capUserId, 'test.capacity.unlimited', 0));

$admin->query(
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_user_entitlement'
    . ' (fk_i_user_id, s_feature, i_quantity, dt_expiration, s_source, dt_date)'
    . ' VALUES (' . $capUserId . ", 'test.capacity.lapsed', 99, '"
    . date('Y-m-d H:i:s', time() - 3600) . "', 'grant', NOW())"
);
pin('a lapsed row does not count toward capacity', 3, EntitlementStore::capacity($capUserId, 'test.capacity.lapsed', 3));

FeatureRegistry::getInstance()->register('test.capacity.guarded', array(
    'label'    => 'Guarded capacity feature',
    'consumes' => Feature::CONSUMES_CAPACITY,
    'apply'    => static function (int $userId) {
        return true;
    },
));
EntitlementStore::grant($capUserId, 'test.capacity.guarded', 20, null);
check(
    'consume() refuses a feature the registry declares capacity',
    EntitlementStore::consume($capUserId, 'test.capacity.guarded', 1) === false
);
pin(
    'the refused consume leaves the ceiling exactly as granted',
    20,
    EntitlementStore::capacity($capUserId, 'test.capacity.guarded', 0)
);

/* A capacity entitlement can only ever RAISE a limit, never lower one -- $default
 * is a floor, not merely what to return when there is no row at all. Without this,
 * a global cap of 20 plus a bought entitlement of 10 would leave the paying seller
 * with 10. */
EntitlementStore::grant($capUserId, 'test.capacity.floor', 3, null);
pin(
    'capacity() returns the global default when it is higher than the entitlement',
    10,
    EntitlementStore::capacity($capUserId, 'test.capacity.floor', 10)
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
EntitlementStore::grant($limitUserId, 'listing.photos', 25, null);
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
EntitlementStore::grant($raisedCapUserId, 'listing.photos', 20, null);
pin('a listing.photos entitlement raises a finite global cap', 20, osc_max_images_for_user($raisedCapUserId));

/* ...but a smaller entitlement must never lower it. */
$belowCapUserId = seed_user($admin, 'belowcap', 'belowcap@example.test');
EntitlementStore::grant($belowCapUserId, 'listing.photos', 2, null);
pin('a capacity entitlement below the global cap never lowers it', 4, osc_max_images_for_user($belowCapUserId));

osc_set_preference('numImages@items', '0', 'osclass', 'INTEGER');
osc_reset_preferences();

EntitlementStore::grant($limitUserId, 'listing.no_wait', null, 30);
pin('a listing.no_wait entitlement waives the wait entirely', 0, osc_items_wait_time_for_user($limitUserId));

/* ----------------------------------------------------------------------------
 * The account-menu gate. Showing both entries on the billing switch alone hands every
 * seller of a cap-only site two links to an empty state. A configured gateway is
 * registered by this point (see the registry section above), so the packages side moves.
 * ------------------------------------------------------------------------- */
harness_section('hBilling: wallet/buy links appear only where they lead somewhere');

$menuClasses = static function (): array {
    $out = array();
    foreach (osc_apply_filter('user_menu_filter', array()) as $option) {
        $out[] = $option['class'];
    }

    return $out;
};

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

PackageStore::update($packageId, array(
    's_name'     => 'Test bundle',
    'i_amount'   => 5_000_000,
    's_currency' => 'USD',
    'i_credits'  => 250,
    'b_enabled'  => 0,
));
pin('nothing on sale -> neither link is offered', array(), $menuClasses());

PackageStore::update($packageId, array(
    's_name'     => 'Test bundle',
    'i_amount'   => 5_000_000,
    's_currency' => 'USD',
    'i_credits'  => 250,
    'b_enabled'  => 1,
));
pin(
    'a package plus a configured gateway -> both links are offered',
    array('opt_billing_wallet', 'opt_billing_buy'),
    $menuClasses()
);

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
pin('billing off -> neither link, whatever is on sale', array(), $menuClasses());

/* ----------------------------------------------------------------------------
 * Premium and the category counts. An expired listing counts only while premium,
 * the same rule the daily recount uses, so switching premium must move the count.
 * ------------------------------------------------------------------------- */
harness_section('Premium: category counts follow the switch');

$countRoot  = seed_category($admin, 'Count root');
$countChild = seed_category($admin, 'Count child', $countRoot);
$catCount   = static function (int $catId) use ($admin): int {
    $row = $admin->query(
        'SELECT i_num_items FROM ' . DB_TABLE_PREFIX . 't_category_stats WHERE fk_i_category_id = ' . $catId
    )->fetch_assoc();

    return (int) ($row['i_num_items'] ?? 0);
};

$lapsed = seed_item($admin, $countChild, $userId, 'Lapsed listing');
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE pk_i_id = ' . $lapsed);
$live = seed_item($admin, $countChild, $userId, 'Live listing');
\mindstellar\utility\Utils::updateCategoryStatsById($countChild);
pin('only the unexpired listing counts to start', 1, $catCount($countChild));

$actions = new ItemActions(true);
$actions->premium($lapsed, true);
pin('premium on an expired listing adds it', 2, $catCount($countChild));
pin('the parent follows', 2, $catCount($countRoot));

$actions->premium($lapsed, false);
pin('premium off an expired listing takes it away', 1, $catCount($countChild));

$actions->premium($live, true);
pin('premium on an unexpired listing changes nothing', 1, $catCount($countChild));
$actions->premium($live, false);
pin('premium off an unexpired listing changes nothing', 1, $catCount($countChild));

$actions->premium($lapsed, true);
$actions->spam($lapsed, true);
pin('spam takes an expired premium listing away', 1, $catCount($countChild));
$actions->spam($lapsed, false);
pin('unspam brings it back', 2, $catCount($countChild));

$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_item SET b_spam = 1 WHERE pk_i_id = ' . $lapsed);
\mindstellar\utility\Utils::updateCategoryStatsById($countChild);
pin('a spam listing is not counted', 1, $catCount($countChild));
$actions->spam($lapsed, false);
pin('unspam counts an expired premium listing', 2, $catCount($countChild));

Item::getInstance()->updateExpirationDate($lapsed, date('Y-m-d H:i:s', time() + 86400));
pin('extending an expired premium listing does not count it twice', 2, $catCount($countChild));
Item::getInstance()->updateExpirationDate($lapsed, date('Y-m-d H:i:s', time() - 86400));
pin('expiring a premium listing keeps it counted', 2, $catCount($countChild));

$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . 't_item SET dt_premium_expiration = DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    . ' WHERE pk_i_id = ' . $lapsed
);
Premium::expire();
pin('the sweep ending premium takes an expired listing away', 1, $catCount($countChild));
pin('the parent follows the sweep', 1, $catCount($countRoot));

/* ----------------------------------------------------------------------------
 * Receipts: e-mailed once per paid order, retried from the job queue, and a page
 * only the buyer or an admin can open.
 * ------------------------------------------------------------------------- */
harness_section('Receipts');

// Defined before hJobs.php, whose own copy is guarded: a test can make the queue throw.
if (!function_exists('osc_job_enqueue')) {
    function osc_job_enqueue(string $type, array $payload = array(), array $options = array()): int
    {
        if (isset($GLOBALS['enqueueThrows'])) {
            throw $GLOBALS['enqueueThrows'];
        }

        return \mindstellar\job\JobQueue::getInstance()->enqueue($type, $payload, $options);
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
if (!function_exists('_osc_from_email_aux')) {
    function _osc_from_email_aux()
    {
        return 'site@example.test';
    }
}
foreach (array('osc_page_title' => 'Test site', 'osc_locale_dec_point' => '.', 'osc_locale_thousands_sep' => ',',
    'osc_current_user_locale' => 'en_US') as $fn => $value) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ' . var_export($value, true) . '; }');
    }
}
if (!function_exists('osc_format_date')) {
    function osc_format_date($date, $format = null)
    {
        return substr((string) $date, 0, 10);
    }
}

$mails = array();
$mailWorks = true;
Receipts::$mailer = static function (array $params) use (&$mails, &$mailWorks): bool {
    if ($mailWorks) {
        $mails[] = $params;
    }

    return $mailWorks;
};
$receiptJobs = static function (int $orderId) use ($admin): int {
    return (int) $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_job_queue WHERE s_type = 'billing.receipt'"
        . " AND s_unique = 'order:" . $orderId . "'"
    )->fetch_assoc()['c'];
};

osc_set_preference(Receipts::PREF_EMAIL, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
$quiet = OrderStore::create($userId, 'refundy', 2_500_000, 'USD', 30);
Billing::markPaid($quiet, 'ext_quiet');
pin('with receipts off nothing is sent', 0, count($mails));
pin('and nothing is queued', 0, $receiptJobs($quiet->getId()));

osc_set_preference(Receipts::PREF_EMAIL, '', Billing::PREF_GROUP);
osc_reset_preferences();
check('receipts are on until an admin switches them off', Receipts::emailEnabled());

$bought = OrderStore::create($userId, 'refundy', 2_500_000, 'USD', 30);
Billing::markPaid($bought, 'pi_receipt_1');
pin('paying an order sends one receipt', 1, count($mails));
pin('to the buyer', 'buyer@example.test', $mails[0]['to'] ?? null);
check('the subject names the order', strpos((string) ($mails[0]['subject'] ?? ''), '#' . $bought->getId()) !== false);
check('the body has the amount', strpos((string) ($mails[0]['body'] ?? ''), '2.50 USD') !== false);
check('the body has the credits', strpos((string) ($mails[0]['body'] ?? ''), '30 credits') !== false);
check('the body has the provider reference', strpos((string) ($mails[0]['body'] ?? ''), 'pi_receipt_1') !== false);
check('the order records the receipt as sent', OrderStore::find($bought->getId())->meta(OrderStore::RECEIPT_SENT) !== null);

Billing::markPaid(OrderStore::find($bought->getId()), 'pi_receipt_1');
Billing::markPaid(OrderStore::find($bought->getId()), 'pi_receipt_1', true);
pin('a replay and an admin mark-paid send nothing more', 1, count($mails));
check('sending again by hand sends nothing more', Receipts::send($bought->getId()));
pin('still one receipt', 1, count($mails));

$mailWorks = false;
$retried   = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 10);
Billing::markPaid($retried, 'pi_receipt_2');
pin('a failed send settles the order anyway', Order::STATUS_PAID, OrderStore::find($retried->getId())->getStatus());
pin('and queues one retry job', 1, $receiptJobs($retried->getId()));
pin('no receipt is marked sent', null, OrderStore::find($retried->getId())->meta(OrderStore::RECEIPT_SENT));

Receipts::registerJobs();
$handler = \mindstellar\job\JobRegistry::handler(Receipts::JOB);
$job     = new \mindstellar\job\Job(array('pk_i_id' => 1, 's_type' => Receipts::JOB, 'i_attempts' => 0), array('order_id' => $retried->getId()));
$threw = false;
try {
    $handler($job);
} catch (RuntimeException $e) {
    $threw = true;
}
check('the job throws while mail still fails, so the queue retries', $threw);

$mailWorks = true;
$mails     = array();
$handler($job);
pin('the job sends the receipt', 1, count($mails));
$handler($job);
pin('running the job again sends nothing more', 1, count($mails));

$thrower = static function (array $params): bool {
    throw new RuntimeException('smtp down');
};
Receipts::$mailer = $thrower;
$crashed = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 10);
check('a mailer that throws does not break settlement', Billing::markPaid($crashed, 'pi_receipt_3'));
pin('and the receipt is queued', 1, $receiptJobs($crashed->getId()));
Receipts::$mailer = $thrower;
$noQueue = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 10);
OrderStore::settle($noQueue->getId(), Order::STATUS_PAID, 'pi_receipt_4');
$GLOBALS['enqueueThrows'] = new RuntimeException('queue down');
$escaped = null;
try {
    Receipts::afterPaid(OrderStore::find($noQueue->getId()));
} catch (Throwable $e) {
    $escaped = get_class($e);
}
unset($GLOBALS['enqueueThrows']);
pin('a queue that throws as well does not escape afterPaid()', null, $escaped);
pin('the order stays paid', Order::STATUS_PAID, OrderStore::find($noQueue->getId())->getStatus());

$GLOBALS['enqueueThrows'] = new RuntimeException('queue down');
$escaped = null;
try {
    $settledAnyway = Billing::markPaid(OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 10), 'pi_receipt_5');
} catch (Throwable $e) {
    $escaped = get_class($e);
}
unset($GLOBALS['enqueueThrows']);
pin('markPaid survives a failing mailer and a failing queue', null, $escaped);
check('and reports the order settled', !empty($settledAnyway));

$sendCalls = 0;
Receipts::$mailer = static function (array $params) use (&$mails, &$sendCalls): bool {
    $sendCalls++;
    $mails[] = $params;

    return true;
};

$stillPending = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 1);
check('send() on a pending order reports nothing to do', Receipts::send($stillPending->getId()));
pin('and calls no mailer', 0, $sendCalls);
pin('and leaves no sent mark', null, OrderStore::find($stillPending->getId())->meta(OrderStore::RECEIPT_SENT));

$refundedFirst = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 1);
OrderStore::settle($refundedFirst->getId(), Order::STATUS_PAID, 'pi_receipt_6');
OrderStore::refund($refundedFirst->getId());
check('send() on a refunded order reports nothing to do', Receipts::send($refundedFirst->getId()));
pin('and calls no mailer either', 0, $sendCalls);
pin('and leaves no sent mark either', null, OrderStore::find($refundedFirst->getId())->meta(OrderStore::RECEIPT_SENT));

$other = seed_user($admin, 'stranger', 'stranger@example.test');
$paidOrder = OrderStore::find($bought->getId());
check('the buyer can view the receipt', Receipts::canView($paidOrder, $userId, false));
check('another user cannot', !Receipts::canView($paidOrder, $other, false));
check('a guest cannot', !Receipts::canView($paidOrder, 0, false));
check('an admin can', Receipts::canView($paidOrder, 0, true));
$pendingOrder = OrderStore::create($userId, 'refundy', 1_000_000, 'USD', 1);
check('a pending order has no receipt, even for its buyer', !Receipts::canView($pendingOrder, $userId, false));
check('or an admin', !Receipts::canView($pendingOrder, 0, true));
Billing::refund($paidOrder);
check('a refunded order keeps its receipt', Receipts::canView(OrderStore::find($bought->getId()), $userId, false));

osc_set_preference(Receipts::PREF_BUSINESS, "Acme <b>Ltd</b>\n1 High St & Co", Billing::PREF_GROUP);
osc_reset_preferences();
pin('business details are escaped, line breaks kept', 'Acme &lt;b&gt;Ltd&lt;/b&gt;<br>' . "\n" . '1 High St &amp; Co', Receipts::businessHtml());
ob_start();
Receipts::render(OrderStore::find($bought->getId()), 'https://example.test/back', 'Back');
$page = (string) ob_get_clean();
check('the page escapes the business details', strpos($page, 'Acme &lt;b&gt;Ltd&lt;/b&gt;') !== false && strpos($page, '<b>Ltd') === false);
check('a refunded order says so', strpos($page, 'rc-status') !== false && strpos($page, 'Refunded') !== false);
check('the page has the print button', strpos($page, 'window.print()') !== false);
check('the e-mail body escapes the business details', strpos(Receipts::emailBody(Receipts::vars(OrderStore::find($bought->getId()))), '<b>Ltd') === false);
$hostile = array(
    'site'     => 'Site <b>x</b>&',
    'number'   => 7,
    'date'     => 'Date <b>x</b>&',
    'credits'  => 'Credits <b>x</b>&',
    'amount'   => 'Amount <b>x</b>&',
    'method'   => 'Gateway <b>x</b>&',
    'ref'      => 'Ref <b>x</b>&',
    'email'    => 'Mail <b>x</b>&',
    'name'     => 'Name',
    'url'      => 'https://example.test/r?a=1&b="2"',
    'business' => '',
    'refunded' => false,
);
$hostileBody = Receipts::emailBody($hostile);
foreach (array('Site', 'Date', 'Credits', 'Amount', 'Gateway', 'Ref', 'Mail') as $what) {
    check('the e-mail escapes the ' . strtolower($what) . ' value', strpos($hostileBody, $what . ' &lt;b&gt;x&lt;/b&gt;&amp;') !== false);
}
check('the e-mail has no raw tag from a value', strpos($hostileBody, '<b>x') === false);
check('the e-mail escapes the receipt link', strpos($hostileBody, 'href="https://example.test/r?a=1&amp;b=&quot;2&quot;"') !== false);

$receipt   = $hostile;
$backUrl   = 'https://example.test/back?a=1&b="2"';
$backLabel = 'Back <b>x</b>&';
ob_start();
require ABS_PATH . 'oc-includes/osclass/gui/billing/receipt.php';
$hostilePage = (string) ob_get_clean();
foreach (array('Site', 'Date', 'Credits', 'Amount', 'Gateway', 'Ref', 'Mail', 'Back') as $what) {
    check('the page escapes the ' . strtolower($what) . ' value', strpos($hostilePage, $what . ' &lt;b&gt;x&lt;/b&gt;&amp;') !== false);
}
check('the page has no raw tag from a value', strpos($hostilePage, '<b>x') === false);
check('the page escapes the back link', strpos($hostilePage, 'href="https://example.test/back?a=1&amp;b=&quot;2&quot;"') !== false);

osc_set_preference(Receipts::PREF_BUSINESS, '', Billing::PREF_GROUP);
osc_set_preference(Receipts::PREF_EMAIL, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();
Receipts::$mailer = null;

osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
