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

require_once dirname(__DIR__) . '/page-fn.php';

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
    <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div');
    osc_gui_empty($emptyMessage, 'oe-bill-empty');
    osc_gui_panel_close('div'); ?>
<?php } else { ?>
    <?php $billingTab = 'buy';
    require ABS_PATH . 'oc-includes/osclass/gui/billing/tabs.php'; ?>
    <?php if ($checkoutHtml !== '' && $order !== null) { ?>
        <?php osc_gui_panel_open(sprintf(_m('Order #%d'), $order->getId()), 'oe-bill-card', '', 'div'); ?>
            <div class="oe-bill-instructions"><?php echo $checkoutHtml; /* gateway-authored, see CheckoutIntent::html() */ ?></div>
        <?php osc_gui_panel_close('div'); ?>
    <?php } ?>

    <?php if (empty($packages)) { ?>
        <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div');
        osc_gui_empty(_m('There are no credit packages for sale right now.'), 'oe-bill-empty');
        osc_gui_panel_close('div'); ?>
    <?php } elseif (empty($gateways)) { ?>
        <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div');
        osc_gui_empty(_m('No payment method is set up yet.'), 'oe-bill-empty');
        osc_gui_panel_close('div'); ?>
    <?php } else { ?>
        <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div');
            $pickerPackages = $packages;
            $pickerGateways = $gateways;
            $pickerFields   = array('page' => 'billing', 'action' => 'checkout');
            $pickerSubmit   = _m('Continue');
            require ABS_PATH . 'oc-includes/osclass/gui/billing/parts/package-picker.php';
        osc_gui_panel_close('div'); ?>
    <?php } ?>
<?php } ?>
</div>
<?php osc_run_hook('account_page_after', 'billing-buy'); ?>
</div>

<?php require ABS_PATH . 'oc-includes/osclass/gui/account/nav.php'; ?>
</div>
