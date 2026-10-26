<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\OrderStore;

/**
 * The buyer's own orders -- markup only: no page chrome, no heading
 * and no stylesheet of its own (core's shell or the theme's chrome supplies
 * all three). Registered as the
 * 'billing/orders' render target (see hBilling.php) so both orders.php (core's
 * standalone fallback) and a theme's user-custom.php can include it.
 *
 * Reads its own data through osc_logged_user_id() and the billing helpers
 * rather than variables exported by CWebBilling, so it renders safely from any
 * context, including a direct ?page=custom&file=billing/orders hit: logged-out
 * or billing-disabled shows a calm empty state, never a warning or someone
 * else's orders.
 */

require_once dirname(__DIR__) . '/page-fn.php';

$perPage = 25;

if (!osc_is_web_user_logged_in()) {
    $emptyMessage = _m('Sign in to see your orders.');
} elseif (!osc_billing_enabled()) {
    $emptyMessage = _m('Orders are not available on this site.');
} else {
    $emptyMessage = null;

    $userId  = osc_logged_user_id();
    $pageNum = max(1, Params::getParamInt('pageNum'));
    $offset  = ($pageNum - 1) * $perPage;

    /** @var \mindstellar\billing\Order[] $orders */
    $orders = OrderStore::forUser($userId, $perPage, $offset);
    $orders = is_array($orders) ? $orders : array();
    $total  = OrderStore::searchCount(array('user_id' => $userId));
}

$shopper = osc_is_shopper() || !osc_billing_credits_enabled();
?>
<div class="oe-account">
<div class="oe-account-main">
<?php osc_run_hook('account_page_before', 'billing-orders'); ?>
<div class="oe-bill">


<?php if ($emptyMessage !== null) { ?>
    <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div');
    osc_gui_empty($emptyMessage, 'oe-bill-empty');
    osc_gui_panel_close(); ?>
<?php } else { ?>
    <?php if (!$shopper) {
        $billingTab = 'orders';
        require ABS_PATH . 'oc-includes/osclass/gui/billing/tabs.php';
        require ABS_PATH . 'oc-includes/osclass/gui/billing/slot-prompt.php';
    } ?>
    <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div'); ?>
        <?php if (empty($orders)) {
            osc_gui_empty($shopper ? _m('You have not ordered anything yet.') : _m('You have not bought any credits yet.'), 'oe-bill-empty');
        } else { ?>
            <?php require ABS_PATH . 'oc-includes/osclass/gui/billing/parts/order-list.php'; ?>
            <?php if ($total > $perPage) {
                $pager = array(
                    'prev'  => $pageNum > 1 ? osc_billing_orders_url() . '&pageNum=' . ($pageNum - 1) : '',
                    'next'  => $pageNum * $perPage < $total ? osc_billing_orders_url() . '&pageNum=' . ($pageNum + 1) : '',
                    'class' => 'oe-bill-pager',
                );
                require dirname(__DIR__) . '/parts/pager.php';
            } ?>
        <?php } ?>
    <?php osc_gui_panel_close(); ?>

    <?php if (!$shopper) { ?>
    <p class="oe-muted oe-bill-sub">
        <a href="<?php echo osc_esc_html(osc_billing_wallet_url()); ?>"><?php echo osc_esc_html(_m('Back to your wallet')); ?></a>
    </p>
    <?php } ?>
<?php } ?>
</div>
<?php osc_run_hook('account_page_after', 'billing-orders'); ?>
</div>

<?php require ABS_PATH . 'oc-includes/osclass/gui/account/nav.php'; ?>
</div>
