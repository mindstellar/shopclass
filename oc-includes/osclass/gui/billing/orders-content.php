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
use mindstellar\billing\Orders;
use mindstellar\billing\Receipts;

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

// Column headings, also each cell's label when a row stacks on a phone.
$col = array(
    'date'    => _m('Date'),
    'method'  => _m('Payment method'),
    'amount'  => _m('Amount'),
    'credits' => _m('Credits'),
    'status'  => _m('Status'),
);
?>
<div class="oe-account">
<div class="oe-account-main">
<?php osc_run_hook('account_page_before', 'billing-orders'); ?>
<div class="oe-bill">


<?php if ($emptyMessage !== null) { ?>
    <div class="oe-panel oe-bill-card">
        <p class="oe-empty oe-bill-empty"><?php echo osc_esc_html($emptyMessage); ?></p>
    </div>
<?php } else { ?>
    <div class="oe-panel oe-bill-card">
        <?php if (empty($orders)) { ?>
            <p class="oe-empty oe-bill-empty"><?php echo osc_esc_html(_m('You have not bought any credits yet.')); ?></p>
        <?php } else { ?>
            <div class="oe-scroll">
            <table>
                <thead>
                <tr>
                    <th scope="col"><?php echo osc_esc_html($col['date']); ?></th>
                    <th scope="col"><?php echo osc_esc_html($col['method']); ?></th>
                    <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html($col['amount']); ?></th>
                    <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html($col['credits']); ?></th>
                    <th scope="col"><?php echo osc_esc_html($col['status']); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order) { ?>
                    <tr>
                        <td data-label="<?php echo osc_esc_html($col['date']); ?>"><?php echo osc_esc_html(osc_format_date($order->getDate())); ?></td>
                        <td data-label="<?php echo osc_esc_html($col['method']); ?>"><?php echo osc_esc_html(osc_billing_gateway_name($order->getGateway())); ?></td>
                        <td class="oe-num oe-bill-num" data-label="<?php echo osc_esc_html($col['amount']); ?>">
                            <?php echo osc_esc_html($formatMoney($order->getAmount(), $order->getCurrency())); ?>
                        </td>
                        <td class="oe-num oe-bill-num" data-label="<?php echo osc_esc_html($col['credits']); ?>"><?php echo osc_esc_html(number_format($order->getCredits())); ?></td>
                        <td data-label="<?php echo osc_esc_html($col['status']); ?>">
                            <span class="oe-badge oe-bill-badge <?php echo osc_esc_html($order->getStatus()); ?>">
                                <?php echo osc_esc_html($statusWords[$order->getStatus()] ?? $order->getStatus()); ?>
                            </span>
                            <?php if (Receipts::available($order)) { ?>
                                <a href="<?php echo osc_esc_html(Receipts::url($order->getId())); ?>"><?php echo osc_esc_html(_m('Receipt')); ?></a>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            </div>
            <?php if ($total > $perPage) { ?>
                <div class="oe-pager oe-bill-pager">
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

    <p class="oe-muted oe-bill-sub">
        <a href="<?php echo osc_esc_html(osc_billing_wallet_url()); ?>"><?php echo osc_esc_html(_m('Back to your wallet')); ?></a>
    </p>
<?php } ?>
</div>
<?php osc_run_hook('account_page_after', 'billing-orders'); ?>
</div>

<?php require ABS_PATH . 'oc-includes/osclass/gui/account/nav.php'; ?>
</div>
