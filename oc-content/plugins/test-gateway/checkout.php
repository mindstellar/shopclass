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
use mindstellar\testgateway\TestGateway;

/*
 * The hosted test checkout: page content only, wrapped by core's page chrome.
 */

$order = __get('testGatewayOrder');
if (!$order instanceof Order) {
    return;
}

$money = number_format($order->getAmount() / 1000000, 2, osc_locale_dec_point(), osc_locale_thousands_sep())
    . ' ' . $order->getCurrency();
$status   = $order->getStatus();
$payUrl   = osc_route_url('test-gateway-pay');
$statuses = array(
    Order::STATUS_PENDING   => __('Pending', 'test-gateway'),
    Order::STATUS_PAID      => __('Paid', 'test-gateway'),
    Order::STATUS_FAILED    => __('Failed', 'test-gateway'),
    Order::STATUS_REFUNDED  => __('Refunded', 'test-gateway'),
    Order::STATUS_CANCELLED => __('Cancelled', 'test-gateway'),
);

$button = static function (string $outcome, string $label, string $class) use ($payUrl, $order): void {
    ?>
    <form method="post" action="<?php echo osc_esc_html($payUrl); ?>" class="nocsrf">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="order" value="<?php echo (int) $order->getId(); ?>">
        <input type="hidden" name="outcome" value="<?php echo osc_esc_html($outcome); ?>">
        <button type="submit" class="<?php echo osc_esc_html($class); ?>"><?php echo osc_esc_html($label); ?></button>
    </form>
    <?php
};
?>
<div class="oe-bill">
    <div class="oe-panel oe-bill-card">
        <p><span class="oe-bill-badge pending"><?php echo osc_esc_html(__('Test mode', 'test-gateway')); ?></span></p>
        <p><?php echo osc_esc_html(__('No money moves on this page. Each button sends the same signed callback a real payment provider would.', 'test-gateway')); ?></p>

        <table class="oe-table">
            <tbody>
            <tr>
                <th scope="row"><?php echo osc_esc_html(__('Order', 'test-gateway')); ?></th>
                <td>#<?php echo (int) $order->getId(); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo osc_esc_html(__('Amount', 'test-gateway')); ?></th>
                <td><?php echo osc_esc_html($money); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo osc_esc_html(__('Credits', 'test-gateway')); ?></th>
                <td><?php echo number_format($order->getCredits()); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo osc_esc_html(__('Status', 'test-gateway')); ?></th>
                <td>
                    <span class="oe-bill-badge <?php echo osc_esc_html($status); ?>">
                        <?php echo osc_esc_html($statuses[$status] ?? $status); ?>
                    </span>
                </td>
            </tr>
            </tbody>
        </table>

        <div class="oe-actions">
            <?php if ($status === Order::STATUS_PENDING) {
                $button('paid', __('Pay (succeeds)', 'test-gateway'), 'oe-btn');
                $button('declined', __('Decline', 'test-gateway'), 'oe-btn oe-btn-danger');
                $button('pending', __('Leave pending', 'test-gateway'), 'oe-btn oe-secondary');
                $button('error', __('Fail with error', 'test-gateway'), 'oe-btn oe-secondary');
            } elseif ($status === Order::STATUS_PAID) {
                $button('refunded', __('Refund (as the provider)', 'test-gateway'), 'oe-btn oe-btn-danger');
            } ?>
        </div>
    </div>

    <?php if (TestGateway::setting('show_payload') && in_array($status, array(Order::STATUS_PENDING, Order::STATUS_PAID), true)) {
        $payload = TestGateway::payload($order, $status === Order::STATUS_PAID ? 'refunded' : 'paid');
        $command = 'curl -i -X POST ' . escapeshellarg(osc_base_url(true) . '?page=billing&action=callback&gateway=' . TestGateway::ID);
        foreach ($payload as $key => $value) {
            $command .= ' -d ' . escapeshellarg($key . '=' . $value);
        }
        ?>
        <div class="oe-panel oe-bill-card">
            <h2><?php echo osc_esc_html(__('Signed callback', 'test-gateway')); ?></h2>
            <p class="oe-hint"><?php echo osc_esc_html(__('Posts to the real callback route. Run it twice: the second run changes nothing.', 'test-gateway')); ?></p>
            <pre class="oe-trace"><code><?php echo osc_esc_html($command); ?></code></pre>
        </div>
    <?php } ?>

    <p class="oe-muted oe-bill-sub">
        <a href="<?php echo osc_esc_html(osc_billing_orders_url()); ?>"><?php echo osc_esc_html(__('Back to your orders', 'test-gateway')); ?></a>
    </p>
</div>
