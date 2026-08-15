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

/**
 * Core's fallback buy page -- rendered only when the active theme has no
 * user-billing-buy.php of its own. See CWebBilling::doView().
 */

$packages = __get('packages');
$packages = is_array($packages) ? $packages : array();
$gateways = __get('gateways');
$gateways = is_array($gateways) ? $gateways : array();

/** @var \mindstellar\billing\Order|null $order */
$order        = __get('order');
$order        = $order instanceof \mindstellar\billing\Order ? $order : null;
$checkoutHtml = __get('checkoutHtml');
$checkoutHtml = is_string($checkoutHtml) ? $checkoutHtml : '';

$formatMoney = static function (int $micros, string $currency): string {
    return number_format($micros / 1000000, 2, osc_locale_dec_point(), osc_locale_thousands_sep())
           . ' ' . strtoupper($currency);
};

$themePath = WebThemes::newInstance()->getCurrentThemePath();
$hasChrome = file_exists($themePath . 'header.php') && file_exists($themePath . 'footer.php');

if ($hasChrome) {
    osc_current_web_theme_path('header.php');
} else {
    ?><!doctype html>
<html lang="<?php echo osc_esc_html(str_replace('_', '-', osc_current_user_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo osc_esc_html(_m('Buy credits')); ?></title>
<style>html,body{margin:0;padding:0;background:#f7f9fb;}</style>
</head>
<body>
    <?php
}
?>
<div class="oe-bill">
<style>
  .oe-bill{max-width:880px;margin:0 auto;padding:32px 16px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#14181f;}
  .oe-bill *{box-sizing:border-box;}
  .oe-bill h1{font-size:1.5rem;font-weight:600;letter-spacing:-.01em;margin:0 0 20px;}
  .oe-bill h2{font-size:1.0625rem;font-weight:600;margin:0 0 12px;}
  .oe-bill .oe-bill-card{background:#fff;border:1px solid #dde3ea;border-radius:6px;padding:20px 24px;margin-bottom:20px;}
  .oe-bill .oe-bill-empty{color:#5f6b7a;padding:24px 0;text-align:center;}
  .oe-bill .oe-bill-btn{display:inline-block;text-decoration:none;border-radius:4px;padding:9px 18px;font-size:.9375rem;font-weight:500;background:#0b7269;color:#fff;border:1px solid #0b7269;cursor:pointer;}
  .oe-bill .oe-bill-btn:hover{background:#09625c;}
  .oe-bill .oe-bill-sub{color:#5f6b7a;margin:4px 0 0;font-size:.875rem;}
  .oe-bill fieldset{border:0;margin:0 0 20px;padding:0;}
  .oe-bill legend{font-weight:600;font-size:1.0625rem;margin:0 0 12px;padding:0;}
  .oe-bill .oe-bill-pkg{border:1px solid #dde3ea;border-radius:6px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:12px;}
  .oe-bill .oe-bill-pkg label{display:flex;align-items:center;gap:12px;width:100%;cursor:pointer;}
  .oe-bill .oe-bill-pkg-name{font-weight:600;}
  .oe-bill .oe-bill-pkg-credits{color:#5f6b7a;font-size:.875rem;}
  .oe-bill .oe-bill-pkg-price{margin-left:auto;font-weight:600;white-space:nowrap;}
  .oe-bill .oe-bill-gw{border:1px solid #dde3ea;border-radius:6px;padding:12px 16px;margin-bottom:10px;}
  .oe-bill .oe-bill-gw label{display:flex;align-items:center;gap:12px;cursor:pointer;}
  .oe-bill .oe-bill-instructions{white-space:pre-line;}
</style>

<h1><?php echo osc_esc_html(_m('Buy credits')); ?></h1>

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
        <form method="post" action="<?php echo osc_esc_html(osc_billing_buy_url()); ?>">
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

</div>
<?php
if ($hasChrome) {
    osc_current_web_theme_path('footer.php');
} else {
    ?>
</body>
</html>
<?php
}
