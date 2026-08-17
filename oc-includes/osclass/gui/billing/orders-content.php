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
use mindstellar\billing\Orders;

/**
 * The buyer's own orders -- markup only, no page chrome. Registered as the
 * 'billing/orders' render target (see hBilling.php) so both orders.php (core's
 * standalone fallback) and a theme's user-custom.php can include it.
 *
 * Reads its own data through osc_logged_user_id() and the billing helpers
 * rather than variables exported by CWebBilling, so it renders safely from any
 * context, including a direct ?page=custom&file=billing/orders hit: logged-out
 * or billing-disabled shows a calm empty state, never a warning or someone
 * else's orders.
 */

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

    /** @var Order[] $orders */
    $orders = Orders::forUser($userId, $perPage, $offset);
    $orders = is_array($orders) ? $orders : array();
    $total  = Orders::searchCount(array('user_id' => $userId));
}

$statusWords = array(
    Order::STATUS_PENDING   => _m('Pending'),
    Order::STATUS_PAID      => _m('Paid'),
    Order::STATUS_FAILED    => _m('Failed'),
    Order::STATUS_REFUNDED  => _m('Refunded'),
    Order::STATUS_CANCELLED => _m('Cancelled'),
);

$formatMoney = static function (int $micros, string $currency): string {
    return number_format($micros / 1000000, 2, osc_locale_dec_point(), osc_locale_thousands_sep())
           . ' ' . strtoupper($currency);
};
?>
<div class="oe-bill">
<style>
  .oe-bill{max-width:880px;margin:0 auto;padding:32px 16px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#14181f;}
  .oe-bill *{box-sizing:border-box;}
  .oe-bill h1{font-size:1.5rem;font-weight:600;letter-spacing:-.01em;margin:0 0 20px;}
  .oe-bill .oe-bill-card{background:#fff;border:1px solid #dde3ea;border-radius:6px;padding:20px 24px;margin-bottom:20px;}
  .oe-bill .oe-bill-empty{color:#5f6b7a;padding:24px 0;text-align:center;}
  .oe-bill table{width:100%;border-collapse:collapse;font-size:.9375rem;}
  .oe-bill th,.oe-bill td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef1f5;}
  .oe-bill th{color:#5f6b7a;font-weight:500;font-size:.8125rem;text-transform:uppercase;letter-spacing:.02em;}
  .oe-bill .oe-bill-num{text-align:right;font-variant-numeric:tabular-nums;}
  .oe-bill .oe-bill-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:500;}
  .oe-bill .oe-bill-badge.paid{background:#e4f8e7;color:#1d7d3e;}
  .oe-bill .oe-bill-badge.pending{background:#fdf4d2;color:#7a6716;}
  .oe-bill .oe-bill-badge.failed,.oe-bill .oe-bill-badge.cancelled{background:#ffe9e5;color:#c22826;}
  .oe-bill .oe-bill-badge.refunded{background:#eef1f5;color:#5f6b7a;}
  .oe-bill .oe-bill-sub{color:#5f6b7a;margin:4px 0 0;font-size:.875rem;}
  .oe-bill .oe-bill-pager{display:flex;justify-content:space-between;margin-top:16px;font-size:.875rem;}
  .oe-bill .oe-bill-pager a{color:#0b7269;text-decoration:none;}
</style>

<h1><?php echo osc_esc_html(_m('Your orders')); ?></h1>

<?php if ($emptyMessage !== null) { ?>
    <div class="oe-bill-card">
        <p class="oe-bill-empty"><?php echo osc_esc_html($emptyMessage); ?></p>
    </div>
<?php } else { ?>
    <div class="oe-bill-card">
        <?php if (empty($orders)) { ?>
            <p class="oe-bill-empty"><?php echo osc_esc_html(_m('You have not bought any credits yet.')); ?></p>
        <?php } else { ?>
            <table>
                <thead>
                <tr>
                    <th scope="col"><?php echo osc_esc_html(_m('Date')); ?></th>
                    <th scope="col"><?php echo osc_esc_html(_m('Payment method')); ?></th>
                    <th scope="col" class="oe-bill-num"><?php echo osc_esc_html(_m('Amount')); ?></th>
                    <th scope="col" class="oe-bill-num"><?php echo osc_esc_html(_m('Credits')); ?></th>
                    <th scope="col"><?php echo osc_esc_html(_m('Status')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order) { ?>
                    <tr>
                        <td><?php echo osc_esc_html(osc_format_date($order->getDate())); ?></td>
                        <td><?php echo osc_esc_html($order->getGateway()); ?></td>
                        <td class="oe-bill-num">
                            <?php echo osc_esc_html($formatMoney($order->getAmount(), $order->getCurrency())); ?>
                        </td>
                        <td class="oe-bill-num"><?php echo osc_esc_html(number_format($order->getCredits())); ?></td>
                        <td>
                            <span class="oe-bill-badge <?php echo osc_esc_html($order->getStatus()); ?>">
                                <?php echo osc_esc_html($statusWords[$order->getStatus()] ?? $order->getStatus()); ?>
                            </span>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <?php if ($total > $perPage) { ?>
                <div class="oe-bill-pager">
                    <span>
                        <?php if ($pageNum > 1) { ?>
                            <a href="<?php echo osc_esc_html(osc_billing_orders_url() . '&pageNum=' . ($pageNum - 1)); ?>">
                                <?php echo osc_esc_html(_m('Newer')); ?>
                            </a>
                        <?php } ?>
                    </span>
                    <span>
                        <?php if ($pageNum * $perPage < $total) { ?>
                            <a href="<?php echo osc_esc_html(osc_billing_orders_url() . '&pageNum=' . ($pageNum + 1)); ?>">
                                <?php echo osc_esc_html(_m('Older')); ?>
                            </a>
                        <?php } ?>
                    </span>
                </div>
            <?php } ?>
        <?php } ?>
    </div>

    <p class="oe-bill-sub">
        <a href="<?php echo osc_esc_html(osc_billing_wallet_url()); ?>"><?php echo osc_esc_html(_m('Back to your wallet')); ?></a>
    </p>
<?php } ?>
</div>
