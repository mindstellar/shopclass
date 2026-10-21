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
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;
use mindstellar\currency\Money;

/**
 * A table of orders, shared by the orders page and the shopper's dashboard. Expects $orders
 * (Order[]); the caller draws the empty state.
 */

/** @var \mindstellar\billing\Order[] $orders */

$statusWords = array(
    Order::STATUS_PENDING   => _m('Pending'),
    Order::STATUS_PAID      => _m('Paid'),
    Order::STATUS_FAILED    => _m('Failed'),
    Order::STATUS_REFUNDED  => _m('Refunded'),
    Order::STATUS_CANCELLED => _m('Cancelled'),
);

// Column headings, also each cell's label when a row stacks on a phone.
$col = array(
    'date'    => _m('Date'),
    'method'  => _m('Payment method'),
    'amount'  => _m('Amount'),
    'credits' => _m('Credits'),
    'status'  => _m('Status'),
);
?>
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
            <td data-label="<?php echo osc_esc_html($col['method']); ?>"><?php echo osc_esc_html(osc_billing_gateway_name($order->getGateway())); ?>
                <?php if ($order->isListing()) {
                    $itemId = (int) $order->listingId();
                    $state  = OrderStore::listingState($itemId);
                    $link   = $state === 'held' ? osc_billing_publish_url($itemId) : ($state === 'live' ? osc_item_url_ns($itemId) : ''); ?>
                    <br><span class="oe-muted"><?php if ($link !== '') { ?><a href="<?php echo osc_esc_html($link); ?>"><?php echo osc_esc_html($order->getLabel()); ?></a><?php } else { echo osc_esc_html($order->getLabel()); } ?></span>
                <?php } ?>
            </td>
            <td class="oe-num oe-bill-num" data-label="<?php echo osc_esc_html($col['amount']); ?>">
                <?php echo osc_esc_html(Money::format($order->getAmount(), $order->getCurrency())); ?>
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
