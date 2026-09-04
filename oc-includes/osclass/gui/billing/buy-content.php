<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Order;
use mindstellar\billing\PaymentGatewayRegistry;

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
    $gateways = PaymentGatewayRegistry::instance()->available();
}

$order        = __get('order');
$order        = $order instanceof Order ? $order : null;
$checkoutHtml = __get('checkoutHtml');
$checkoutHtml = is_string($checkoutHtml) ? $checkoutHtml : '';

$formatMoney = static function (int $micros, string $currency): string {
    return number_format($micros / 1000000, 2, osc_locale_dec_point(), osc_locale_thousands_sep())
           . ' ' . strtoupper($currency);
};
?>
<div class="oe-bill">


<?php if ($emptyMessage !== null) { ?>
    <div class="oe-bill-card">
        <p class="oe-bill-empty"><?php echo osc_esc_html($emptyMessage); ?></p>
    </div>
<?php } else { ?>
    <?php if ($checkoutHtml !== '' && $order !== null) { ?>
        <div class="oe-bill-card">
            <h2><?php echo osc_esc_html(sprintf(_m('Order #%d'), $order->getId())); ?></h2>
            <div class="oe-bill-instructions"><?php echo $checkoutHtml; /* gateway-authored, see CheckoutIntent::html() */ ?></div>
        </div>
    <?php } ?>

    <?php if (empty($packages)) { ?>
        <div class="oe-bill-card">
            <p class="oe-bill-empty"><?php echo osc_esc_html(_m('There are no credit packages for sale right now.')); ?></p>
        </div>
    <?php } elseif (empty($gateways)) { ?>
        <div class="oe-bill-card">
            <p class="oe-bill-empty"><?php echo osc_esc_html(_m('No payment method is set up yet.')); ?></p>
        </div>
    <?php } else { ?>
        <div class="oe-bill-card">
            <?php
            // Posts to index.php, not osc_billing_buy_url(): the buy page has a permalink,
            // and a matched rewrite rule writes its own params over the request (see
            // Rewrite::applyParams()) -- so posting 'checkout' at the URL whose route says
            // 'buy' arrives as 'buy' and silently re-renders this picker. The route lives in
            // the hidden fields below, where nothing overwrites it.
            ?>
            <form method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
                <input type="hidden" name="page" value="billing"/>
                <input type="hidden" name="action" value="checkout"/>

                <fieldset>
                    <legend><?php echo osc_esc_html(_m('Choose a package')); ?></legend>
                    <?php foreach ($packages as $i => $package) { ?>
                        <div class="oe-bill-pkg">
                            <label>
                                <input type="radio" name="packageId" value="<?php echo (int) $package['pk_i_id']; ?>"
                                    <?php echo $i === 0 ? 'checked' : ''; ?> required/>
                                <span class="oe-bill-pkg-name"><?php echo osc_esc_html($package['s_name']); ?></span>
                                <span class="oe-bill-pkg-credits">
                                    <?php echo osc_esc_html(sprintf(_m('%s credits'), number_format((int) $package['i_credits']))); ?>
                                </span>
                                <span class="oe-bill-pkg-price">
                                    <?php echo osc_esc_html($formatMoney((int) $package['i_amount'], (string) $package['s_currency'])); ?>
                                </span>
                            </label>
                        </div>
                    <?php } ?>
                </fieldset>

                <fieldset>
                    <legend><?php echo osc_esc_html(_m('Payment method')); ?></legend>
                    <?php $first = true;
                    foreach ($gateways as $gatewayId => $gateway) { ?>
                        <div class="oe-bill-gw">
                            <label>
                                <input type="radio" name="gateway" value="<?php echo osc_esc_html($gatewayId); ?>"
                                    <?php echo $first ? 'checked' : ''; ?> required/>
                                <?php echo osc_esc_html($gateway->getName()); ?>
                            </label>
                        </div>
                        <?php $first = false;
                    } ?>
                </fieldset>

                <button type="submit" class="oe-bill-btn"><?php echo osc_esc_html(_m('Continue')); ?></button>
            </form>
        </div>
    <?php } ?>

    <p class="oe-bill-sub">
        <a href="<?php echo osc_esc_html(osc_billing_orders_url()); ?>"><?php echo osc_esc_html(_m('View your past orders')); ?></a>
    </p>
<?php } ?>
</div>
