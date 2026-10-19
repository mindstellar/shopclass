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
 * The pay step of a listing held at the end of posting: another seller is sent away, the page
 * shows the right step (credits, packages that cover the shortfall, waiting, paid but not
 * published, published), paying with credits publishes once, an order takes its amount from
 * the package, request data is never printed and the CSRF check comes before any write.
 * Usage: php tests/billing-publish-page.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PackageStore;
use mindstellar\billing\PaymentGateway;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;
use mindstellar\database\Db;

$admin = api_admin_boot('osc_billing_publish_page');
$p     = DB_TABLE_PREFIX;

foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}
if (!function_exists('_mn')) {
    function _mn($single, $plural, $count)
    {
        return $count == 1 ? $single : $plural;
    }
}
if (!function_exists('osc_item_url_from_item')) {
    function osc_item_url_from_item($item, $locale = '')
    {
        return WEB_PATH . 'listing/' . (int) $item['pk_i_id'];
    }
}
// The account nav's links and page tests, which live in helpers a test cannot load.
foreach (array('osc_user_dashboard_url', 'osc_user_list_items_url', 'osc_user_alerts_url', 'osc_user_public_profile_url', 'osc_user_profile_url', 'osc_change_user_email_url', 'osc_user_logout_url') as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { return WEB_PATH . "' . $fn . '"; }');
    }
}
foreach (array('osc_is_user_dashboard', 'osc_is_list_items', 'osc_is_list_alerts', 'osc_is_user_profile', 'osc_is_change_email_page', 'osc_is_change_password_page', 'osc_is_change_username_page', 'osc_is_current_page', 'osc_get_osclass_location') as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { return false; }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Cars', null, 'en_US', 1, 30);
foreach (array('enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_currency' => 'USD') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
foreach (array(Billing::PREF_ENABLED, 'billing_slot_enabled') as $k) {
    osc_set_preference($k, '1', $k === Billing::PREF_ENABLED ? Billing::PREF_GROUP : 'osclass', 'BOOLEAN');
}
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_credits', '3', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_quantity', '1', 'osclass', 'INTEGER');
osc_set_preference('moderate_items', '-1', 'osclass', 'INTEGER');
scratchdb_forget_cache();
osc_reset_preferences();
osc_register_billing_slot();

/** The secured base controller, stubbed: a redirect ends the request. */
class WebSecBaseModel
{
    protected $action;

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function redirectTo($url, $code = null)
    {
        throw new PayPageRedirect((string) $url);
    }

    public function do404()
    {
        throw new PayPageRedirect('404');
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }
}

final class PayPageRedirect extends RuntimeException
{
    public function __construct(public string $url)
    {
        parent::__construct($url);
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebBilling.php';

/** The real controller, with the page drawn from core's partial instead of the theme. */
final class PayPageBilling extends CWebBilling
{
    public function doView($file)
    {
        $GLOBALS['view'] = $file;
        require ABS_PATH . 'oc-includes/osclass/gui/billing/publish-content.php';
    }
}

/** A gateway that sends the buyer away, or shows its instructions in the page. */
final class PayPageGateway implements PaymentGateway
{
    public function __construct(private string $id, private bool $inPage = false)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return 'Gateway ' . $this->id;
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
        return $this->inPage
            ? CheckoutIntent::html('<p class="bank">Pay into account 42</p>')
            : CheckoutIntent::redirect('https://pay.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored();
    }
}
PaymentGatewayRegistry::getInstance()->register(new PayPageGateway('ppgw'));
PaymentGatewayRegistry::getInstance()->register(new PayPageGateway('ppbank', true));
$five = PackageStore::create(array('s_name' => 'Five pack', 'i_amount' => 2_000_000, 's_currency' => 'USD', 'i_credits' => 5));
$two  = PackageStore::create(array('s_name' => 'Two pack', 'i_amount' => 900_000, 's_currency' => 'USD', 'i_credits' => 2));

$seq    = 0;
$seller = static function (int $credits, string $title = '') use ($admin, $cat, &$seq, $p): array {
    $seq++;
    $uid  = seed_user($admin, 'payer' . $seq, 'payer' . $seq . '@example.test');
    seed_item($admin, $cat, $uid, 'Live ' . $seq);
    $held = seed_item($admin, $cat, $uid, $title !== '' ? $title : 'Held ' . $seq);
    $admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL, dt_expiration = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE pk_i_id = {$held}");
    if ($credits > 0) {
        Wallet::credit($uid, $credits, Wallet::REASON_GRANT);
    }

    return array($uid, $held);
};

/** One request to the pay step as $userId: GET, or POST with a good CSRF token. */
function pay(int $userId, $itemId, ?array $post = null): array
{
    osc_web_user_apply_identity(User::getInstance()->findByPrimaryKey($userId));
    foreach (array('state', 'item', 'order', 'checkoutHtml', 'packages', 'gateways') as $key) {
        View::getInstance()->_exportVariableToView($key, null);
    }
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = array('page' => 'billing', 'action' => 'publish', 'itemId' => (string) $itemId);
    $_POST = $post === null ? array() : $post + array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue());
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    Params::init();
    $GLOBALS['flashes'] = array();
    $GLOBALS['view']    = null;

    $redirect = null;
    ob_start();
    try {
        (new PayPageBilling())->doModel();
    } catch (PayPageRedirect $r) {
        $redirect = $r->url;
    }

    return array('redirect' => $redirect, 'flashes' => implode(' | ', $GLOBALS['flashes']), 'drawn' => (string) ob_get_clean());
}

$row    = static fn (int $id): array => $admin->query("SELECT b_enabled, b_active, dt_first_pub_date FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_assoc();
$orders = static fn (int $uid): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_order WHERE fk_i_user_id = {$uid}")->fetch_row()[0];

harness_section('who may open it');

[$uid, $held] = $seller(5);
[$other]      = $seller(5);
$r = pay($other, $held);
pin('another seller goes to their listings', WEB_PATH . 'osc_user_list_items_url', $r['redirect']);
check('with a flash saying why', str_contains($r['flashes'], 'does not belong to you'));
$r = pay($other, $held, array('do' => 'credits'));
pin('and cannot pay for it either', array(WEB_PATH . 'osc_user_list_items_url', null, 5), array($r['redirect'], $row($held)['dt_first_pub_date'], Wallet::balance($uid)));

harness_section('enough credits');

$r = pay($uid, $held);
pin('the page is the pay step', array(null, 'user-billing-publish.php'), array($r['redirect'], $GLOBALS['view']));
check('it offers the credits', str_contains($r['drawn'], 'Use 3 credits and publish') && str_contains($r['drawn'], 'value="credits"'));
check('with the price and the balance', str_contains($r['drawn'], 'This listing costs 3 credits. You have 5 credits.'));
check('no package picker', !str_contains($r['drawn'], 'name="packageId"'));
check('pay later and delete', str_contains($r['drawn'], 'Pay later') && str_contains($r['drawn'], 'value="item_delete"') && str_contains($r['drawn'], 'data-osc-confirm'));

$r = pay($uid, $held, array('do' => 'credits'));
pin('paying publishes and goes to the listing', array(WEB_PATH . 'listing/' . $held, '1', '1'), array($r['redirect'], $row($held)['b_enabled'], $row($held)['b_active']));
pin('three credits spent', 2, Wallet::balance($uid));
check('with the published flash', str_contains($r['flashes'], 'ok:Your listing has been published'));
$r = pay($uid, $held, array('do' => 'credits'));
pin('again: already published, nothing spent', array(WEB_PATH . 'listing/' . $held, 2), array($r['redirect'], Wallet::balance($uid)));
check('with a flash saying so', str_contains($r['flashes'], 'already published'));
$r = pay($uid, $held);
check('the page says it is published, with a link', str_contains($r['drawn'], 'Your listing is published.') && str_contains($r['drawn'], 'href="' . WEB_PATH . 'listing/' . $held . '"'));
check('and offers nothing to pay or delete', !str_contains($r['drawn'], 'value="credits"') && !str_contains($r['drawn'], 'item_delete'));

harness_section('short of credits');

[$uid, $held] = $seller(0, 'Red <i>car</i>');
$r = pay($uid, $held);
check('the covering package is offered', str_contains($r['drawn'], 'Five pack') && str_contains($r['drawn'], 'value="' . $five . '"'));
check('the one that does not cover it is not', !str_contains($r['drawn'], 'Two pack'));
check('with the gateways and the order button', str_contains($r['drawn'], 'value="ppgw"') && str_contains($r['drawn'], 'value="order"') && str_contains($r['drawn'], 'Continue to payment'));
check('no credits button', !str_contains($r['drawn'], 'value="credits"'));
check('the title is escaped', str_contains($r['drawn'], 'Red &lt;i&gt;car&lt;/i&gt;') && !str_contains($r['drawn'], '<i>car'));

$before = $orders($uid);
$r      = pay($uid, $held, array('do' => 'order', 'packageId' => $two, 'gateway' => 'ppgw'));
pin('a package too small: back to the pay step, no order', array(osc_billing_publish_url($held), $before), array($r['redirect'], $orders($uid)));
check('with the reason', str_contains($r['flashes'], 'does not cover'));
$r = pay($uid, $held, array('do' => 'credits'));
pin('credits it does not have: back, still held', array(osc_billing_publish_url($held), null), array($r['redirect'], $row($held)['dt_first_pub_date']));
check('with the reason', str_contains($r['flashes'], 'not have enough credits'));

$r     = pay($uid, $held, array('do' => 'order', 'packageId' => $five, 'gateway' => 'ppgw', 'amount' => 1, 'credits' => 99));
$order = OrderStore::listingOrder($held);
pin('the order goes to the gateway', 'https://pay.test/' . ($order?->getId() ?? 0), $r['redirect']);
pin('a listing order with the package amount', array(true, $held, 2_000_000, 5), array($order?->isListing(), $order?->listingId(), $order?->getAmount(), $order?->getCredits()));

$r = pay($uid, $held);
check('back from the gateway it waits for the payment', str_contains($r['drawn'], 'Waiting for the payment to be confirmed.') && str_contains($r['drawn'], 'Refresh'));
check('and offers to pay again', str_contains($r['drawn'], 'Pay again') && str_contains($r['drawn'], 'Continue to payment'));

Billing::markPaid(OrderStore::find($order->getId()));
$r = pay($uid, $held);
check('once paid the listing is published', str_contains($r['drawn'], 'Your listing is published.'));
pin('and live', '1', $row($held)['b_enabled']);

harness_section('instructions in the page');

[$uid, $held] = $seller(0);
$r = pay($uid, $held, array('do' => 'order', 'packageId' => $five, 'gateway' => 'ppbank'));
pin('no redirect', null, $r['redirect']);
check('the gateway instructions are shown', str_contains($r['drawn'], '<p class="bank">Pay into account 42</p>') && str_contains($r['drawn'], 'Order #'));
check('beside the waiting step', str_contains($r['drawn'], 'Waiting for the payment to be confirmed.'));
$r = pay($uid, $held);
check('a plain visit shows no instructions', !str_contains($r['drawn'], 'Pay into account 42'));

harness_section('paid, not published');

[$uid, $held] = $seller(0);
$order = mindstellar\billing\ListingCheckout::startOrder($held, $uid, $five, 'ppgw')['order'];
Db::execute("UPDATE {$p}t_billing_order SET s_status = 'paid' WHERE pk_i_id = ?", array($order->getId()));
OrderStore::notePublishFailed($order->getId(), 'insufficient_credits');
Wallet::credit($uid, 5, Wallet::REASON_GRANT);
$r = pay($uid, $held);
check('the page says the payment arrived, and why it is not live', str_contains($r['drawn'], 'Your payment arrived, but the listing is not published yet: the price went up before the payment arrived.'));
check('and offers the credits that fix it', str_contains($r['drawn'], 'Use 3 credits and publish'));

[$uid, $held] = $seller(0);
$order = mindstellar\billing\ListingCheckout::startOrder($held, $uid, $five, 'ppgw')['order'];
Db::execute("UPDATE {$p}t_billing_order SET s_status = 'paid' WHERE pk_i_id = ?", array($order->getId()));
OrderStore::notePublishFailed($order->getId(), 'refused');
Wallet::credit($uid, 5, Wallet::REASON_GRANT);
$r = pay($uid, $held);
check('a refusal says so and points to the site owner', str_contains($r['drawn'], 'this site does not allow it to be published.') && str_contains($r['drawn'], 'Contact the site owner.'));
check('with no retry button that would only be refused again', !str_contains($r['drawn'], 'value="credits"'));

harness_section('a publish that throws');

[$uid, $held] = $seller(5);
$boom = static function (): void {
    throw new RuntimeException('disk full');
};
osc_add_hook('posted_item', $boom);
$r = pay($uid, $held, array('do' => 'credits'));
osc_remove_hook('posted_item', $boom);
pin('the seller is sent back to the pay step, still held, nothing spent', array(osc_billing_publish_url($held), null, 5), array($r['redirect'], $row($held)['dt_first_pub_date'], Wallet::balance($uid)));
check('with an error flash, not a crash', str_contains($r['flashes'], 'error:This listing could not be published. Try again later.'));

harness_section('where a credits publish lands');

osc_set_preference('moderate_items', '0', 'osclass', 'INTEGER');
osc_set_preference('logged_user_item_validation', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();
[$uid, $held] = $seller(5);
$r = pay($uid, $held, array('do' => 'credits'));
pin('waiting for the e-mail check: to My listings', array(WEB_PATH . 'osc_user_list_items_url', '1', '0'), array($r['redirect'], $row($held)['b_enabled'], $row($held)['b_active']));
check('with the validation flash', str_contains($r['flashes'], 'ok:Check your inbox to validate your listing'));
$r = pay($uid, $held);
check('the pay step says so too, with no listing link', str_contains($r['drawn'], 'Check your inbox to validate your listing') && !str_contains($r['drawn'], 'View your listing') && !str_contains($r['drawn'], 'Your listing is published.'));
osc_set_preference('moderate_items', '-1', 'osclass', 'INTEGER');
osc_reset_preferences();

[$uid, $held] = $seller(5);
$block = static function ($item) use ($held): void {
    Db::execute('UPDATE ' . DB_TABLE_PREFIX . 't_item SET b_enabled = 0 WHERE pk_i_id = ?', array($held));
};
osc_add_hook('posted_item', $block);
$r = pay($uid, $held, array('do' => 'credits'));
osc_remove_hook('posted_item', $block);
pin('held for an admin: to My listings', array(WEB_PATH . 'osc_user_list_items_url', '0'), array($r['redirect'], $row($held)['b_enabled']));
check('with the approval flash', str_contains($r['flashes'], 'ok:Your listing will be published after an admin approves it.'));
$r = pay($uid, $held);
check('the pay step says it waits for an admin', str_contains($r['drawn'], 'after an admin approves it') && !str_contains($r['drawn'], 'not waiting for payment'));

harness_section('request data');

[$uid, $held] = $seller(5);
$r = pay($uid, $held . '<b>');
check('the page renders for the id', str_contains($r['drawn'], 'value="' . $held . '"'));
check('the raw id is never printed', !str_contains($r['drawn'], '<b>') && !str_contains($r['drawn'], '&lt;b&gt;'));
$r = pay($uid, $held, array('do' => 'nope'));
pin('an unknown step goes back to the pay step', array(osc_billing_publish_url($held), null), array($r['redirect'], $row($held)['dt_first_pub_date']));

harness_section('CSRF before any write');

$src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebBilling.php');
preg_match('/private function publishPost\(\)\s*\{(.*?)\n    \}/s', $src, $m);
$body  = $m[1] ?? '';
$check = strpos($body, 'osc_csrf_check();');
$write = min(array_filter(array(strpos($body, 'publishWithCredits('), strpos($body, 'ListingCheckout::'), PHP_INT_MAX), 'is_int'));
check('publishPost() checks the token first', $check !== false && $check < $write);

$_SERVER['REQUEST_METHOD'] = 'GET';
exit(harness_result());
