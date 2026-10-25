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

use mindstellar\billing\Order;

/**
 * Package picker and payment methods -- markup only: no page chrome, no heading
 * and no stylesheet of its own (core's shell or the theme's chrome supplies
 * all three). Registered
 * as the 'billing/buy' render target (see hBilling.php) so both buy.php (core's
 * standalone fallback) and a theme's user-custom.php can include it.
 *
 * Packages and gateways are read through osc_logged_user_id() and the billing
 * helpers rather than variables exported by CWebBilling, so the picker itself
 * renders safely from any context, including a direct
 * ?page=custom&file=billing/buy hit. $order/$checkoutHtml are the one exception:
 * a freshly computed checkout result that only ever exists on the same request
 * CWebBilling::checkoutPost() produced it on, so a bare hit simply has none to
 * show.
 */

if (!osc_is_web_user_logged_in()) {
    $emptyMessage = _m('Sign in to buy credits.');
} elseif (!osc_billing_enabled()) {
    $emptyMessage = _m('Buying credits is not available on this site.');
} else {
    $emptyMessage = null;

    $packages = osc_billing_packages();
    $gateways = osc_billing_gateways();
}

$order        = __get('order');
$order        = $order instanceof Order ? $order : null;
$checkoutHtml = __get('checkoutHtml');
$checkoutHtml = is_string($checkoutHtml) ? $checkoutHtml : '';
?>
<div class="oe-account">
<div class="oe-account-main">
<?php osc_run_hook('account_page_before', 'billing-buy'); ?>
<div class="oe-bill">


<?php if ($emptyMessage !== null) { ?>
    <div class="oe-panel oe-bill-card">
        <p class="oe-empty oe-bill-empty"><?php echo osc_esc_html($emptyMessage); ?></p>
    </div>
<?php } else { ?>
    <?php $billingTab = 'buy';
    require ABS_PATH . 'oc-includes/osclass/gui/billing/tabs.php'; ?>
    <?php if ($checkoutHtml !== '' && $order !== null) { ?>
        <div class="oe-panel oe-bill-card">
            <h2><?php echo osc_esc_html(sprintf(_m('Order #%d'), $order->getId())); ?></h2>
            <div class="oe-bill-instructions"><?php echo $checkoutHtml; /* gateway-authored, see CheckoutIntent::html() */ ?></div>
        </div>
    <?php } ?>

    <?php if (empty($packages)) { ?>
        <div class="oe-panel oe-bill-card">
            <p class="oe-empty oe-bill-empty"><?php echo osc_esc_html(_m('There are no credit packages for sale right now.')); ?></p>
        </div>
    <?php } elseif (empty($gateways)) { ?>
        <div class="oe-panel oe-bill-card">
            <p class="oe-empty oe-bill-empty"><?php echo osc_esc_html(_m('No payment method is set up yet.')); ?></p>
        </div>
    <?php } else { ?>
        <div class="oe-panel oe-bill-card">
            <?php
            $pickerPackages = $packages;
            $pickerGateways = $gateways;
            $pickerFields   = array('page' => 'billing', 'action' => 'checkout');
            $pickerSubmit   = _m('Continue');
            require ABS_PATH . 'oc-includes/osclass/gui/billing/parts/package-picker.php';
            ?>
        </div>
    <?php } ?>
<?php } ?>
</div>
<?php osc_run_hook('account_page_after', 'billing-buy'); ?>
</div>

<?php require ABS_PATH . 'oc-includes/osclass/gui/account/nav.php'; ?>
</div>
