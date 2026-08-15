<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Billing;
use mindstellar\billing\Orders;
use mindstellar\billing\Packages;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;

/**
 * The buyer-facing side of billing: wallet, checkout, orders, and featuring a listing.
 *
 * Every action needs a logged-in user (see CWebBillingNonSecure for the one route that
 * cannot) and every action refuses when billing is switched off, so nothing here has to
 * repeat that check itself.
 *
 * Class CWebBilling
 */
class CWebBilling extends WebSecBaseModel
{
    /** Rows per page of the wallet ledger and the orders list. */
    private const PER_PAGE = 25;

    /**
     * Theme view => core fallback, for the pages a theme may not know about yet.
     * See doView().
     */
    private const FALLBACK_VIEWS = array(
        'user-billing-wallet.php' => 'wallet.php',
        'user-billing-buy.php'    => 'buy.php',
        'user-billing-orders.php' => 'orders.php',
    );

    public function __construct()
    {
        parent::__construct();

        if (!osc_billing_enabled()) {
            osc_add_flash_error_message(_m('Billing is not available on this site'));
            $this->redirectTo(osc_base_url());
        }

        osc_run_hook('init_billing');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('buy'):
                $this->buyView();
                break;
            case ('checkout'):
                $this->checkoutPost();
                break;
            case ('orders'):
                $this->ordersView();
                break;
            case ('upgrade'):
                $this->upgradePost();
                break;
            default:
                $this->walletView();
                break;
        }
    }

    /**
     * Balance and ledger history. The template this renders is a theme's own
     * (user-billing-wallet.php) or core's fallback -- neither exists yet.
     */
    private function walletView()
    {
        $userId = osc_logged_user_id();
        $page   = max(1, Params::getParamInt('pageNum'));
        $offset = ($page - 1) * self::PER_PAGE;

        $this->_exportVariableToView('balance', Wallet::balance($userId));
        $this->_exportVariableToView('entries', Wallet::history($userId, self::PER_PAGE, $offset));
        $this->_exportVariableToView('total', Wallet::historyCount($userId));
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);
        $this->doView('user-billing-wallet.php');
    }

    /**
     * The enabled packages and the configured gateways, for a buyer to choose from.
     */
    private function buyView()
    {
        $this->_exportVariableToView('packages', Packages::enabled());
        $this->_exportVariableToView('gateways', PaymentGatewayRegistry::instance()->available());
        $this->doView('user-billing-buy.php');
    }

    /**
     * The user's own orders.
     */
    private function ordersView()
    {
        $userId = osc_logged_user_id();
        $page   = max(1, Params::getParamInt('pageNum'));
        $offset = ($page - 1) * self::PER_PAGE;

        $this->_exportVariableToView('orders', Orders::forUser($userId, self::PER_PAGE, $offset));
        $this->_exportVariableToView('total', Orders::searchCount(array('user_id' => $userId)));
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);
        $this->doView('user-billing-orders.php');
    }

    /**
     * Start a checkout for a package.
     *
     * The package id is the only thing about the price that comes from the browser --
     * the amount, currency and credits are read from the package row, never trusted
     * from the request. A package that has since been disabled or removed, or a
     * gateway that cannot take the package's currency, both fail the same honest way:
     * back to the buy page with a flash message, not a broken checkout.
     */
    private function checkoutPost()
    {
        osc_csrf_check();

        $userId    = osc_logged_user_id();
        $packageId = Params::getParamInt('packageId');
        $gatewayId = Params::getParamString('gateway');

        $package = Packages::find($packageId);
        if ($package === null || empty($package['b_enabled'])) {
            osc_add_flash_error_message(_m('That package is no longer available'));
            $this->redirectTo($this->url('buy'));
        }

        $available = PaymentGatewayRegistry::instance()->available((string) $package['s_currency']);
        if (!isset($available[$gatewayId])) {
            osc_add_flash_error_message(_m('That payment method is not available'));
            $this->redirectTo($this->url('buy'));
        }

        $order = Orders::create(
            $userId,
            $gatewayId,
            (int) $package['i_amount'],
            (string) $package['s_currency'],
            (int) $package['i_credits']
        );

        $intent = Billing::checkout($order);
        if ($intent === null) {
            osc_add_flash_error_message(_m('Payment is unavailable right now. Please try again later.'));
            $this->redirectTo($this->url('buy'));
        }

        if ($intent->isRedirect()) {
            $this->redirectTo($intent->getPayload());
        }

        // A rendered (non-redirect) intent, e.g. the offline gateway's instructions,
        // is shown on the buy page itself rather than a dedicated screen of its own.
        $this->_exportVariableToView('checkoutHtml', $intent->getPayload());
        $this->_exportVariableToView('order', $order);
        $this->_exportVariableToView('packages', Packages::enabled());
        $this->_exportVariableToView('gateways', PaymentGatewayRegistry::instance()->available());
        $this->doView('user-billing-buy.php');
    }

    /**
     * Feature one of the user's own listings.
     */
    private function upgradePost()
    {
        osc_csrf_check();

        $userId = osc_logged_user_id();
        $itemId = Params::getParamInt('itemId');

        $item = Item::newInstance()->findByPrimaryKey($itemId);
        if (empty($item) || (int) $item['fk_i_user_id'] !== $userId) {
            osc_add_flash_error_message(_m('That listing does not belong to you'));
            $this->redirectTo(osc_user_list_items_url());
        }

        if (!empty($item['b_premium'])) {
            osc_add_flash_warning_message(_m('This listing is already featured'));
            $this->redirectTo(osc_user_list_items_url());
        }

        if (osc_billing_premium_credits() <= 0) {
            osc_add_flash_error_message(_m('Featuring a listing is not available right now'));
            $this->redirectTo(osc_user_list_items_url());
        }

        $spent = Billing::spend($userId, 'listing.premium', array(
            'itemId'   => $itemId,
            'ref_type' => 'item',
            'ref_id'   => $itemId,
        ));

        if ($spent) {
            osc_add_flash_ok_message(sprintf(_m('Listing featured for %d days'), osc_billing_premium_days()));
        } else {
            osc_add_flash_error_message(sprintf(
                _m('Not enough credits to feature this listing. <a href="%s">Buy more credits</a>'),
                osc_esc_html(osc_billing_buy_url())
            ));
        }

        $this->redirectTo(osc_user_list_items_url());
    }

    private function url(string $action = ''): string
    {
        $url = osc_base_url() . '?page=billing';

        return $action === '' ? $url : $url . '&action=' . $action;
    }

    //hopefully generic...

    /**
     * Renders through the theme when it supplies the file, and through core's own
     * fallback under oc-includes/osclass/gui/billing/ otherwise -- the bundled theme is
     * an external repository (see osc_current_web_theme_path()) and may not carry these
     * views yet, but a site must not be left with a dead wallet/buy/orders page for that.
     *
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');

        if (isset(self::FALLBACK_VIEWS[$file]) && !$this->themeProvides($file)) {
            $this->doFallbackView(self::FALLBACK_VIEWS[$file]);
        } else {
            osc_current_web_theme_path($file);
        }

        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
    }

    /**
     * Whether the site's *active* theme supplies $file directly. Deliberately not
     * osc_current_web_theme_path()'s own check -- that walks on to a parent theme and
     * then to the internal gui/storefront theme, either of which could exist without
     * having ever heard of billing, and would render blank instead of falling through
     * to core's fallback.
     */
    private function themeProvides(string $file): bool
    {
        return file_exists(WebThemes::newInstance()->getCurrentThemePath() . $file);
    }

    private function doFallbackView(string $file): void
    {
        $path = osc_base_path() . 'oc-includes/osclass/gui/billing/' . $file;
        if (file_exists($path)) {
            require $path;
        }
    }
}

/* file end: ./oc-includes/osclass/classes/controller/CWebBilling.php */
