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
use mindstellar\billing\Feature;
use mindstellar\billing\FeatureRegistry;
use mindstellar\billing\ItemUpgrades;
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

    /** upgradeState(): the item does not currently hold the feature. */
    private const HELD_NOT = 'not_held';
    /** upgradeState(): held, with a live expiration -- extendable. */
    private const HELD_LIVE = 'live';
    /** upgradeState(): held forever -- nothing to extend. */
    private const HELD_PERMANENT = 'permanent';

    /** decideUpgrade(): not held (or a lapsed hold) -- spend() runs as a fresh grant. */
    private const DECISION_PROCEED = 'proceed';
    /** decideUpgrade(): held, live, and worth topping up. */
    private const DECISION_EXTEND = 'extend';
    /** decideUpgrade(): held forever -- refuse, nothing to extend. */
    private const DECISION_REFUSE_PERMANENT = 'refuse_permanent';
    /** decideUpgrade(): held and live, but not a duration to extend (item.bump's cooldown). */
    private const DECISION_REFUSE_HELD = 'refuse_held';

    /**
     * Theme view => core fallback, for the pages a theme may not know about yet.
     * See doView().
     */
    private const FALLBACK_VIEWS = array(
        'user-billing-wallet.php' => 'wallet.php',
        'user-billing-buy.php'    => 'buy.php',
        'user-billing-orders.php' => 'orders.php',
    );

    /**
     * Theme view => registered render-target id, for doView()'s middle
     * resolution step (a theme's own user-custom.php, given the content).
     */
    private const RENDER_TARGETS = array(
        'user-billing-wallet.php' => 'billing/wallet',
        'user-billing-buy.php'    => 'billing/buy',
        'user-billing-orders.php' => 'billing/orders',
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
        switch ($this->resolveAction()) {
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
     * The action this request is actually asking for.
     *
     * A matched rewrite rule writes its own params over the request (see
     * Rewrite::applyParams()), so once the buy page gained a permalink, a form posting
     * action=checkout at that page's own URL arrived as action=buy and silently
     * re-rendered the picker with nothing bought. Themes have posted checkout there since
     * before the URL had a route -- core's own fallback did -- so the body wins on a POST:
     * it is what the browser asked for, and the route only ever described where the form
     * was rendered. Nothing new is reachable this way; every action still runs its own
     * osc_csrf_check() and its own validation.
     *
     * @return string
     */
    private function resolveAction()
    {
        if (strtoupper((string) Params::getServerParam('REQUEST_METHOD', false, false)) !== 'POST') {
            return (string) $this->action;
        }

        $posted = Params::getParamsAsArray('post');

        return isset($posted['action']) && is_string($posted['action']) && $posted['action'] !== ''
            ? $posted['action']
            : (string) $this->action;
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
     * Feature or upgrade one of the user's own listings.
     *
     * 'feature' defaults to listing.premium so links minted before this route took
     * other features keep working unchanged. Whatever the request names, it is
     * validated against the item-scoped allow-list before it ever reaches
     * Billing::spend() -- a feature id is attacker-reachable input, and only a
     * feature a site has explicitly marked Feature::SCOPE_ITEM may be spent this
     * way (see FeatureRegistry::register()'s 'scope' key).
     */
    private function upgradePost()
    {
        osc_csrf_check();

        $userId    = osc_logged_user_id();
        $itemId    = Params::getParamInt('itemId');
        $featureId = Params::getParamString('feature');
        if ($featureId === '') {
            $featureId = 'listing.premium';
        }

        if (!in_array($featureId, self::itemScopedFeatureIds(), true)) {
            osc_add_flash_error_message(_m('That upgrade is not available'));
            $this->redirectTo(osc_user_list_items_url());
        }

        $item = Item::newInstance()->findByPrimaryKey($itemId);
        if (empty($item) || (int) $item['fk_i_user_id'] !== $userId) {
            osc_add_flash_error_message(_m('That listing does not belong to you'));
            $this->redirectTo(osc_user_list_items_url());
        }

        // A feature id in the allow-list is simply unregistered when its own
        // *_enabled preference is off -- listing.premium included, now that it
        // follows the same split as every other feature here.
        $feature = FeatureRegistry::instance()->get($featureId);
        if ($feature === null) {
            osc_add_flash_error_message(_m('This upgrade is not available right now'));
            $this->redirectTo(osc_user_list_items_url());
        }

        $decision = self::decideUpgrade($featureId, $feature, $item);

        if ($decision === self::DECISION_REFUSE_PERMANENT) {
            osc_add_flash_warning_message(_m('This listing already has that upgrade, permanently -- there is nothing to extend'));
            $this->redirectTo(osc_user_list_items_url());
        }

        if ($decision === self::DECISION_REFUSE_HELD) {
            osc_add_flash_warning_message(_m('This listing already has that upgrade'));
            $this->redirectTo(osc_user_list_items_url());
        }

        $extending = $decision === self::DECISION_EXTEND;

        $spent = Billing::spend($userId, $featureId, array(
            'itemId'   => $itemId,
            'ref_type' => 'item',
            'ref_id'   => $itemId,
        ));

        if ($spent) {
            $message = $extending
                ? sprintf(_m('%s extended on this listing'), $feature->getLabel())
                : sprintf(_m('%s applied to this listing'), $feature->getLabel());
            osc_add_flash_ok_message($message);
        } else {
            osc_add_flash_error_message(sprintf(
                _m('Not enough credits for this upgrade. <a href="%s">Buy more credits</a>'),
                osc_esc_html(osc_billing_buy_url())
            ));
        }

        $this->redirectTo(osc_user_list_items_url());
    }

    /**
     * Feature ids reachable through this route: every feature a site has marked
     * Feature::SCOPE_ITEM, built-in or plugin-registered. Anything else -- a
     * user-scoped feature like listing.slot included -- is refused before it
     * reaches Billing::spend(), no matter what the request names.
     *
     * @return string[]
     */
    private static function itemScopedFeatureIds(): array
    {
        $ids = array();
        foreach (FeatureRegistry::instance()->all() as $id => $feature) {
            if ($feature->getScope() === Feature::SCOPE_ITEM) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Whether to let a purchase through as a fresh grant, extend a live hold, or
     * refuse -- and if it refuses, why.
     *
     * A held-but-live duration feature (a highlight or a premium spot about to
     * lapse) is the case this exists to allow: a seller topping it up before it
     * runs out is a better outcome than making them wait for it to lapse first,
     * which is why upgradeState() == HELD_LIVE is not a blanket refusal. Two
     * exceptions stay refused: a permanent hold has nothing to extend, and
     * item.bump's hold is not a benefit at all -- it is the cooldown that stops
     * a listing being re-bumped to the top on a timer, so a feature that does
     * not consume a duration keeps being refused while its row is live, exactly
     * as before this method existed.
     */
    private static function decideUpgrade(string $featureId, Feature $feature, array $item): string
    {
        $state = self::upgradeState($featureId, $item);

        if ($state === self::HELD_NOT) {
            return self::DECISION_PROCEED;
        }
        if ($state === self::HELD_PERMANENT) {
            return self::DECISION_REFUSE_PERMANENT;
        }

        return $feature->getConsumes() === Feature::CONSUMES_DURATION
            ? self::DECISION_EXTEND
            : self::DECISION_REFUSE_HELD;
    }

    /**
     * Whether $item currently holds $featureId, and whether that hold is live
     * (has an expiration still ahead of it) or permanent (nothing to extend).
     *
     * listing.premium reads the item row's own columns -- it predates
     * t_item_upgrade and still lives directly on t_item. dt_premium_expiration,
     * not b_premium alone, decides "still held": the flag stays set from the
     * moment it lapses until the hourly sweep runs, and reading the flag alone
     * would refuse to re-feature the listing for that entire window.
     */
    private static function upgradeState(string $featureId, array $item): string
    {
        if ($featureId === 'listing.premium') {
            if (empty($item['b_premium'])) {
                return self::HELD_NOT;
            }
            $expiresAt = $item['dt_premium_expiration'] ?? null;
            if ($expiresAt === null) {
                return self::HELD_PERMANENT;
            }

            return $expiresAt > date('Y-m-d H:i:s') ? self::HELD_LIVE : self::HELD_NOT;
        }

        $itemId = (int) $item['pk_i_id'];
        if (!ItemUpgrades::has($itemId, $featureId)) {
            return self::HELD_NOT;
        }

        return ItemUpgrades::expiresAt($itemId, $featureId) === null ? self::HELD_PERMANENT : self::HELD_LIVE;
    }

    private function url(string $action = ''): string
    {
        $url = osc_base_url() . '?page=billing';

        return $action === '' ? $url : $url . '&action=' . $action;
    }

    //hopefully generic...

    /**
     * Three-step resolution, in order:
     *   1. the active theme ships $file itself (e.g. user-billing-wallet.php) --
     *      it fully owns the page;
     *   2. else the active theme ships user-custom.php -- the theme's account
     *      chrome renders the registered billing render target, the same way it
     *      already renders a plugin's page (see CWebCustom::doModel());
     *   3. else core's own standalone fallback under
     *      oc-includes/osclass/gui/billing/ -- the bundled theme is an external
     *      repository (see osc_current_web_theme_path()) and may not carry any
     *      of the above yet, but a site must not be left with a dead
     *      wallet/buy/orders page for that.
     *
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');

        if (isset(self::FALLBACK_VIEWS[$file])) {
            if ($this->themeProvides($file)) {
                osc_current_web_theme_path($file);
            } elseif ($this->themeProvides('user-custom.php')) {
                $this->_exportVariableToView('file', self::RENDER_TARGETS[$file]);
                Params::setParam('in_user_menu', true);
                osc_current_web_theme_path('user-custom.php');
            } else {
                $this->doFallbackView(self::FALLBACK_VIEWS[$file]);
            }
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
