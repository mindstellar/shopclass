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

use mindstellar\billing\gateway\CashOnDeliveryGateway;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\currency\CurrencyService;

require_once dirname(__DIR__, 2) . '/page-fn.php';

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
    Order::STATUS_SHIPPED   => _m('Shipped'),
    Order::STATUS_DELIVERED => _m('Delivered'),
);

// The credits column is for sites that sell credits; a list of shop orders has none.
$showCredits = false;
foreach ($orders as $order) {
    $showCredits = $showCredits || $order->getCredits() > 0;
}

// Column headings, also each cell's label when a row stacks on a phone.
$col = array(
    'number'  => _m('Order'),
    'date'    => _m('Date'),
    'method'  => _m('Payment method'),
    'amount'  => _m('Amount'),
    'credits' => _m('Credits'),
    'status'  => _m('Status'),
);
$currencies = CurrencyService::make();
?>
<div class="oe-scroll">
<table class="oe-table">
    <thead>
    <tr>
        <th scope="col"><?php echo osc_esc_html($col['number']); ?></th>
        <th scope="col"><?php echo osc_esc_html($col['date']); ?></th>
        <th scope="col"><?php echo osc_esc_html($col['method']); ?></th>
        <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html($col['amount']); ?></th>
        <?php if ($showCredits) { ?>
        <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html($col['credits']); ?></th>
        <?php } ?>
        <th scope="col"><?php echo osc_esc_html($col['status']); ?></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $order) { ?>
        <tr>
            <td data-label="<?php echo osc_esc_html($col['number']); ?>"><?php echo osc_esc_html(Receipts::number($order)); ?>
                <?php if ($order->isGoods()) { ?><br><span class="oe-muted"><?php echo osc_esc_html($order->getLabel()); ?></span><?php } ?>
            </td>
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
                <?php echo osc_esc_html($currencies->money($order->getAmount(), $order->getCurrency())); ?>
            </td>
            <?php if ($showCredits) { ?>
            <td class="oe-num oe-bill-num" data-label="<?php echo osc_esc_html($col['credits']); ?>"><?php echo osc_esc_html(number_format($order->getCredits())); ?></td>
            <?php } ?>
            <td data-label="<?php echo osc_esc_html($col['status']); ?>">
                <span class="oe-badge oe-bill-badge <?php echo osc_esc_html($order->getStatus()); ?>">
                    <?php
                    $word = $statusWords[$order->getStatus()] ?? $order->getStatus();
                    if ($order->isGoods() && $order->isPending()) {
                        $word = $order->getGateway() === CashOnDeliveryGateway::ID ? _m('Pay on delivery') : _m('Awaiting payment');
                    }
                    echo osc_esc_html($word); ?>
                </span>
                <?php if (Receipts::available($order)) { ?>
                    <a href="<?php echo osc_esc_html(Receipts::url($order->getId())); ?>"><?php echo osc_esc_html(_m('Invoice')); ?></a>
                <?php } ?>
                <?php if ($order->buyerMayCancel()) { ?>
                    <form class="oe-inline-form nocsrf" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
                        <?php echo osc_csrf_token_form(); ?>
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="cancel"/>
                        <input type="hidden" name="id" value="<?php echo (int) $order->getId(); ?>"/>
                        <button type="submit" class="oe-link-btn oe-danger-link" data-osc-confirm="<?php
                            echo osc_esc_html(sprintf(_m('Cancel order %s?'), Receipts::number($order))); ?>"><?php
                            echo osc_esc_html(_m('Cancel')); ?></button>
                    </form>
                    <?php osc_gui_print_confirm_script(); ?>
                <?php } ?>
            </td>
        </tr>
        <?php $howToPay = PaymentGatewayRegistry::getInstance()->instructions($order);
        if ($howToPay !== null) { ?>
        <tr class="oe-bill-howto">
            <td colspan="<?php echo $showCredits ? 6 : 5; ?>" class="oe-muted"><?php echo osc_gui_payment_text($howToPay, array(Receipts::number($order))); ?></td>
        </tr>
        <?php } ?>
    <?php } ?>
    </tbody>
</table>
</div>
