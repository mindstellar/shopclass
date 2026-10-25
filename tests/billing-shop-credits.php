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
 * A shop hides credits: the buy, wallet and pay-to-publish pages are 404 while the order pages
 * stay, the account menu has no credit links, and a receipt has no wallet link. Classifieds
 * keeps all of it, and switching back brings it back.
 * Usage: php tests/billing-shop-credits.php
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
use mindstellar\billing\Receipts;

$admin = api_admin_boot('osc_billing_shop_credits');

foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {}');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

seed_locale($admin);
seed_currency($admin);
foreach (array('enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_currency' => 'USD') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
scratchdb_forget_cache();
osc_reset_preferences();

/** The secured base controller, stubbed: a redirect or a 404 ends the request. */
class WebSecBaseModel
{
    protected $action;

    protected $page = 'billing';

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function redirectTo($url, $code = null)
    {
        throw new ShopCreditsStop((string) $url);
    }

    public function do404()
    {
        throw new ShopCreditsStop('404');
    }

    protected function redirectShopperHome(): void
    {
        if (osc_is_shopper() && osc_shopper_page_hidden((string) $this->page, (string) $this->action)) {
            $this->redirectTo('dashboard');
        }
    }

    public function _exportVariableToView($key, $value)
    {
    }
}

final class ShopCreditsStop extends RuntimeException
{
    public function __construct(public string $url)
    {
        parent::__construct($url);
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebBilling.php';

/** The real controller; the view is only named. */
final class ShopCreditsBilling extends CWebBilling
{
    public function doView($file)
    {
        throw new ShopCreditsStop('view:' . $file);
    }
}

final class ShopCreditsGateway implements PaymentGateway
{
    public function getId(): string
    {
        return 'scgw';
    }

    public function getName(): string
    {
        return 'Gateway';
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
        return CheckoutIntent::redirect('https://pay.test/' . $order->getId());
    }

    public function handleCallback(array $request): CallbackResult
    {
        return CallbackResult::ignored();
    }
}
PaymentGatewayRegistry::getInstance()->register(new ShopCreditsGateway());
PackageStore::create(array('s_name' => 'Five pack', 'i_amount' => 2_000_000, 's_currency' => 'USD', 'i_credits' => 5));

$uid = seed_user($admin, 'amy', 'amy@example.test');
osc_web_user_apply_identity(User::getInstance()->findByPrimaryKey($uid));

/** Where a GET of the billing page with $action ends: '404', a redirect or 'view:<file>'. */
function shop_credits_open(string $action): string
{
    $_GET  = array('page' => 'billing', 'action' => $action);
    $_POST = array();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    Params::init();
    try {
        (new ShopCreditsBilling())->doModel();
    } catch (ShopCreditsStop $s) {
        return $s->url;
    }

    return '';
}

$mode = static function (string $mode): void {
    osc_set_preference('site_mode', $mode);
    osc_reset_preferences();
};
$menu = static fn (): array => array_column(osc_apply_filter('user_menu_filter', array()), 'class');
$order  = OrderStore::create($uid, 'scgw', 2_000_000, 'USD', 5);
$wallet = static fn (): string => (string) Receipts::vars($order)['wallet'];

harness_section('classifieds');
$mode('classifieds');
check('credits are on', osc_billing_credits_enabled() && osc_billing_can_buy());
pin('the buy and wallet pages draw', array('view:user-billing-buy.php', 'view:user-billing-wallet.php'), array(shop_credits_open('buy'), shop_credits_open('')));
pin('the account menu links Credits and Buy credits', array('opt_billing_wallet', 'opt_billing_buy'), $menu());
check('the receipt links the wallet', $wallet() !== '');

harness_section('shop');
$mode('shop');
check('credits are off', !osc_billing_credits_enabled() && !osc_billing_can_buy());
pin('buy, wallet, checkout, upgrades and the pay step are 404', array_fill(0, 6, '404'), array_map('shop_credits_open', array('buy', '', 'checkout', 'upgrade', 'account_upgrade', 'publish')));
pin('the order list still draws', 'view:user-billing-orders.php', shop_credits_open('orders'));
pin('the account menu has no credit links', array(), array_values(array_intersect(array('opt_billing_wallet', 'opt_billing_buy'), $menu())));
pin('the receipt has no wallet link', '', $wallet());

harness_section('shop, with an admin signed in');
$adminId = api_admin_seed_admin($admin, 'boss');
Session::getInstance()->_setEphemeral('adminId', $adminId);
Session::getInstance()->_setEphemeral('adminStamp', \mindstellar\auth\AuthStamp::of(Admin::getInstance()->findByPrimaryKey($adminId)));
check('not a shopper', !osc_is_shopper());
pin('the buy page is still 404', '404', shop_credits_open('buy'));
pin('and the menu has no credit links', array(), array_values(array_intersect(array('opt_billing_wallet', 'opt_billing_buy'), $menu())));

harness_section('back to classifieds');
$mode('classifieds');
pin('the buy page draws again', 'view:user-billing-buy.php', shop_credits_open('buy'));
pin('and the menu links come back', array('opt_billing_wallet', 'opt_billing_buy'), $menu());

exit(harness_result());
