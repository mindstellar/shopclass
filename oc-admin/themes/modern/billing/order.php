<?php if (!defined('OC_ADMIN')) {
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

use mindstellar\billing\ListingCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStock;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
));

/** @var \mindstellar\billing\Order $order */
$order   = __get('order');
$entries = __get('entries');
$typed   = is_array(__get('typed')) ? __get('typed') : array();
$errors  = is_array(__get('errors')) ? __get('errors') : array();
// The carrier, tracking number and link boxes, shared by the ship and edit-tracking forms.
$carriers = is_array(__get('carriers')) ? __get('carriers') : array();
$trackingFields = static function (string $prefix, array $values) use ($errors, $carriers): void {
    echo '<div class="order-tracking-fields">';
    osc_admin_text(array(
        'id'    => $prefix . '-carrier',
        'name'  => 'carrier',
        'label' => __('Carrier'),
        'value' => (string) ($values['carrier'] ?? ''),
        'attrs' => array('maxlength' => 100) + ($carriers === array() ? array() : array('list' => 'order-carriers', 'autocomplete' => 'off')),
    ));
    if ($carriers !== array()) {
        echo '<div class="order-carrier-chips" role="group" aria-label="' . osc_esc_html(__('Common carriers')) . '">';
        foreach ($carriers as $carrierName) {
            echo '<button type="button" class="order-carrier-chip" data-osc-fill="#' . osc_esc_html($prefix) . '-carrier" data-osc-fill-value="'
                . osc_esc_html((string) $carrierName) . '">' . osc_esc_html((string) $carrierName) . '</button>';
        }
        echo '</div>';
    }
    osc_admin_text(array(
        'id'    => $prefix . '-number',
        'name'  => 'tracking',
        'label' => __('Tracking number'),
        'value' => (string) ($values['tracking'] ?? ''),
        'attrs' => array('maxlength' => 100),
    ));
    osc_admin_text(array(
        'id'    => $prefix . '-url',
        'name'  => 'trackingUrl',
        'label' => __('Tracking link'),
        'value' => (string) ($values['trackingUrl'] ?? ''),
        'error' => $errors['trackingUrl'] ?? '',
        'attrs' => array('maxlength' => 500, 'placeholder' => 'https://'),
        'help'  => __('Optional. Must start with https://'),
    ));
    echo '</div>';
};
$balance = (int)__get('balance');
$refundable = (bool)__get('refundable');
$dashboardUrl = (string) __get('dashboardUrl');
$isGoods    = $order->isGoods();
$lines      = is_array(__get('lines')) ? __get('lines') : array();
$fulfil     = $order->fulfilment() ?? array();
$address    = is_array($fulfil['address'] ?? null) ? $fulfil['address'] : array();
$buyer      = is_array($order->meta('_buyer')) ? $order->meta('_buyer') : array();
$short      = OrderStock::short($order);
$trackUrl   = Receipts::httpsUrl((string) ($fulfil['tracking_url'] ?? ''));
$refundSent = $order->isPaid() && $order->meta(OrderStore::REFUND_REQUESTED) !== null;

// The view layer hands back '' for anything exported as null, so "absent" has to be
// tested for what it is rather than compared against null. Both of these are genuinely
// optional: the buyer's account may have been deleted, and the plugin that took the
// payment may have been uninstalled since.
$user    = is_array(__get('user')) ? __get('user') : null;
$gateway = is_object(__get('gateway')) ? __get('gateway') : null;
// Payment plugin off or not set up: the screen says so instead of hiding actions.
$gatewayLabel   = $gateway !== null ? $gateway->getName() : $order->getGateway();
$gatewayWarning = '';
if ($gateway === null) {
    $gatewayWarning = sprintf(__('The %s payment plugin is not active.'), $gatewayLabel);
} elseif (!$gateway->isConfigured()) {
    $gatewayWarning = sprintf(__('The %s payment plugin is not set up.'), $gatewayLabel);
}
$pluginsUrl = osc_admin_base_url(true) . '?page=plugins';
$userName = $user !== null ? \mindstellar\user\UserStore::displayName($user) : __('this user');

$base       = osc_admin_base_url(true) . '?page=billing';
$actionUrl  = osc_admin_base_url(true);

$statusWords = array(
    Order::STATUS_PENDING   => __('Pending'),
    Order::STATUS_PAID      => __('Paid'),
    Order::STATUS_SHIPPED   => __('Shipped'),
    Order::STATUS_DELIVERED => __('Delivered'),
    Order::STATUS_FAILED    => __('Failed'),
    Order::STATUS_REFUNDED  => __('Refunded'),
    Order::STATUS_CANCELLED => __('Cancelled'),
);
$statusWord = $statusWords[$order->getStatus()] ?? $order->getStatus();

$reasonWords = array(
    'purchase' => __('Purchase'),
    'spend'    => __('Spent'),
    'refund'   => __('Refund'),
    'grant'    => __('Added by admin'),
    'revoke'   => __('Removed by admin'),
);

$rows = array(
    array(
        'label' => __('Status'),
        'value' => '<span class="osc-status status-' . osc_esc_html($order->getStatus()) . '">'
                   . osc_esc_html($statusWord) . '</span>',
        'html'  => true,
    ),
    array('label' => __('Amount'), 'value' => osc_admin_money($order->getAmount(), $order->getCurrency())),
);
if ($isGoods) {
    $rows[] = array('label' => _x('Order', 'purchase order'), 'value' => $order->getLabel());
} else {
    $rows[] = array('label' => __('Credits'), 'value' => number_format($order->getCredits()));
}

$listingId = $order->listingId();
if ($listingId !== null) {
    $rows[] = array(
        'label' => __('Listing'),
        'value' => OrderStore::listingState($listingId) === 'gone'
            ? osc_esc_html($order->getLabel()) . ' <span class="text-muted">' . osc_esc_html(__('(deleted)')) . '</span>'
            : '<a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . $listingId) . '">'
              . osc_esc_html($order->getLabel()) . '</a>',
        'html'  => true,
    );
}

if ($user !== null) {
    $rows[] = array(
        'label' => __('User'),
        'value' => '<a href="' . osc_esc_html($base . '&action=wallet&userId=' . $order->getUserId()) . '">'
                   . osc_esc_html($userName) . '</a>'
                   . ' <span class="text-muted">' . osc_esc_html($user['s_email']) . '</span>',
        'html'  => true,
    );
    if (!$isGoods) {
        $rows[] = array('label' => __('Balance now'), 'value' => number_format($balance) . ' ' . __('credits'));
    }
} else {
    $rows[] = array('label' => __('User'), 'value' => __('This account has been deleted'));
}

$rows[] = array(
    'label' => __('Payment method'),
    'value' => $gateway !== null
        ? $gateway->getName()
        // The plugin that took this payment is not active. The order still has to
        // be readable and reconcilable, so the stored id stands in for the missing name.
        : sprintf(__('%s (plugin not active)'), $order->getGateway()),
);
$rows[] = array(
    'label' => __('Reference'),
    'value' => $order->getExternalRef() ?: __('None yet'),
    'mono'  => (bool)$order->getExternalRef(),
);
if (Receipts::available($order)) {
    $receiptSent = $order->meta(OrderStore::RECEIPT_SENT);
    $rows[] = array(
        'label' => __('Receipt'),
        'value' => '<a href="' . osc_esc_html($base . '&action=receipt&id=' . $order->getId()) . '" target="_blank" rel="noopener">'
                   . osc_esc_html(__('View receipt')) . '</a>'
                   . ($receiptSent !== null
                       ? ' <span class="text-muted">' . osc_esc_html(__('e-mailed')) . ' '
                         . osc_admin_date((string) $receiptSent, true) . '</span>'
                       : ''),
        'html'  => true,
    );
}
if ($dashboardUrl !== '') {
    $rows[] = array(
        'label' => __('Provider'),
        'value' => '<a href="' . osc_esc_html($dashboardUrl) . '" target="_blank" rel="noopener noreferrer">'
                   . osc_esc_html(__('View payment'))
                   . ' <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>',
        'html'  => true,
    );
}
if ($isGoods && ($fulfil['label'] ?? '') !== '') {
    $rows[] = array('label' => __('Delivery'), 'value' => (string) $fulfil['label']);
}
if ($isGoods && ($fulfil['shipped_at'] ?? null) !== null) {
    $rows[] = array('label' => __('Shipped'), 'value' => osc_admin_date((string) $fulfil['shipped_at'], true), 'html' => true);
    $carrier = trim((string) ($fulfil['carrier'] ?? '') . ' ' . (string) ($fulfil['tracking'] ?? ''));
    if ($carrier !== '' || $trackUrl !== '') {
        $rows[] = array(
            'label' => __('Tracking'),
            'value' => ($trackUrl !== ''
                ? '<a href="' . osc_esc_html($trackUrl) . '" target="_blank" rel="noopener noreferrer">'
                  . osc_esc_html($carrier !== '' ? $carrier : __('Track parcel')) . '</a>'
                : osc_esc_html($carrier)),
            'html'  => true,
        );
    }
}
if ($isGoods && ($fulfil['delivered_at'] ?? null) !== null) {
    $rows[] = array('label' => __('Delivered'), 'value' => osc_admin_date((string) $fulfil['delivered_at'], true), 'html' => true);
}
$rows[] = array('label' => __('Created'), 'value' => osc_admin_date($order->getDate(), true), 'html' => true);
if ($order->getPaidDate() !== null) {
    $rows[] = array('label' => __('Paid'), 'value' => osc_admin_date($order->getPaidDate(), true), 'html' => true);
}
// Keys starting with "_" belong to core, and keys starting with the gateway id and "_" are the plugin's own notes.
$gatewayPrefix = $order->getGateway() . '_';
foreach ($order->getMeta() as $key => $value) {
    if (is_scalar($value) && strpos((string) $key, '_') !== 0 && strpos((string) $key, $gatewayPrefix) !== 0) {
        $rows[] = array('label' => (string)$key, 'value' => (string)$value);
    }
}
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(
        sprintf(__('Order #%d'), $order->getId()),
        array(array('label' => __('Back to orders'), 'url' => $base, 'icon' => 'bi-arrow-left'))
    ); ?>

    <div class="billing-detail-grid">
        <div>
            <?php osc_admin_panel_open(_x('Order', 'purchase order'), array('class' => 'billing-detail-summary')); ?>
                <?php osc_admin_definition($rows); ?>
            <?php osc_admin_panel_close(); ?>

            <?php $publishFailed = $order->meta(OrderStore::PUBLISH_FAILED);
            if ($listingId !== null && is_array($publishFailed)) { ?>
                <div class="callout-warning callout-block mb-3">
                    <div>
                        <?php if (OrderStore::listingState($listingId) === 'live') {
                            echo osc_esc_html(sprintf(__('Paid, but the listing could not be published (%1$s, %2$s). It was published later.'), ListingCheckout::failureReason((string) ($publishFailed['reason'] ?? '')), (string) ($publishFailed['at'] ?? '')));
                        } else {
                            echo osc_esc_html(sprintf(__('Paid, but the listing could not be published: %1$s (%2$s). The credits are in the buyer\'s wallet.'), ListingCheckout::failureReason((string) ($publishFailed['reason'] ?? '')), (string) ($publishFailed['at'] ?? '')));
                        } ?>
                    </div>
                </div>
            <?php } ?>

            <?php if ($isGoods) { ?>
                <?php if ($short !== array()) { ?>
                    <div class="callout-warning callout-block mb-3">
                        <div>
                            <span class="osc-status status-failed"><?php _e('Oversold'); ?></span>
                            <?php _e('Stock ran out after this order was paid. Short:'); ?>
                            <?php
                            $shortWords = array();
                            foreach ($lines as $line) {
                                if (isset($short[$line['id']])) {
                                    $shortWords[] = sprintf(__('%1$s (%2$d short)'), $line['title'], (int) $short[$line['id']]);
                                }
                            }
                            echo osc_esc_html(implode(', ', $shortWords));
                            ?>
                        </div>
                    </div>
                <?php } ?>
                <?php osc_admin_panel_open(__('Items')); ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th scope="col"><?php _e('Item'); ?></th>
                                <th scope="col"><?php _e('SKU'); ?></th>
                                <th scope="col" class="col-numeric"><?php _e('Qty'); ?></th>
                                <th scope="col" class="col-numeric"><?php _e('Unit'); ?></th>
                                <th scope="col" class="col-numeric"><?php _e('Amount'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($lines as $line) {
                                $isItem  = $line['kind'] === 'item';
                                $options = array();
                                foreach ((array) $line['options'] as $name => $value) {
                                    $options[] = $name . ': ' . $value;
                                } ?>
                                <tr>
                                    <td>
                                        <?php if ($isItem && $line['item_id'] !== null) { ?>
                                            <a href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . (int) $line['item_id']); ?>"><?php echo osc_esc_html($line['title']); ?></a>
                                        <?php } else { ?>
                                            <?php echo osc_esc_html($line['title']); ?>
                                        <?php } ?>
                                        <?php if ($options !== array()) { ?>
                                            <br><span class="text-muted"><?php echo osc_esc_html(implode(', ', $options)); ?></span>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo osc_esc_html((string) $line['sku']); ?></td>
                                    <td class="col-numeric"><?php echo $isItem ? (int) $line['qty'] : ''; ?></td>
                                    <td class="col-numeric"><?php echo $isItem ? osc_esc_html(osc_admin_money($line['unit'], $order->getCurrency())) : ''; ?></td>
                                    <td class="col-numeric"><?php echo osc_esc_html($line['kind'] === 'tax' && ($line['meta']['included'] ?? false) === true
                                        ? sprintf(__('%s (included)'), osc_admin_money($line['unit'], $order->getCurrency()))
                                        : osc_admin_money($line['amount'], $order->getCurrency())); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <th scope="row" colspan="4"><?php _e('Total'); ?></th>
                                <td class="col-numeric"><strong><?php echo osc_esc_html(osc_admin_money($order->getAmount(), $order->getCurrency())); ?></strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                <?php osc_admin_panel_close(); ?>

                <?php osc_admin_panel_open(__('Delivery address and buyer')); ?>
                    <p class="mb-0">
                        <?php echo implode('<br>', array_map('osc_esc_html', array_filter(array_map('strval', $address), static fn (string $v): bool => $v !== ''))); ?>
                    </p>
                    <p class="panel-subtitle mt-3 mb-0">
                        <?php echo osc_esc_html(trim((string) ($buyer['name'] ?? ''))); ?>
                        <?php if (($buyer['email'] ?? '') !== '') { ?>
                            · <a href="mailto:<?php echo osc_esc_html(rawurlencode((string) $buyer['email'])); ?>"><?php echo osc_esc_html((string) $buyer['email']); ?></a>
                        <?php } ?>
                        <?php if (($buyer['phone'] ?? '') !== '') { ?>
                            · <?php echo osc_esc_html((string) $buyer['phone']); ?>
                        <?php } ?>
                    </p>
                <?php osc_admin_panel_close(); ?>
            <?php } else { ?>
            <?php osc_admin_form_section(__('Credit movements')); ?>
            <?php } ?>
            <?php if ($isGoods) {
                // A goods order mints no credits, so there is no ledger to show.
            } elseif (empty($entries)) {
                osc_admin_empty(array(
                    'icon'  => 'bi-arrow-left-right',
                    'title' => __('No credits moved yet'),
                    'text'  => __('Credits are added the moment this order is paid.'),
                ));
            } else { ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col"><?php _e('What happened'); ?></th>
                            <th scope="col" class="col-numeric"><?php _e('Change'); ?></th>
                            <th scope="col" class="col-numeric"><?php _e('Balance after'); ?></th>
                            <th scope="col"><?php _e('Date'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $entry) {
                            $amount = (int)$entry['i_amount']; ?>
                            <tr>
                                <td><?php echo osc_esc_html(
                                    $reasonWords[$entry['s_reason']] ?? $entry['s_reason']
                                ); ?></td>
                                <td class="col-numeric">
                                    <?php echo osc_esc_html(($amount > 0 ? '+' : '') . number_format($amount)); ?>
                                </td>
                                <td class="col-numeric"><?php echo number_format((int)$entry['i_balance_after']); ?></td>
                                <td><?php echo osc_admin_date($entry['dt_date'], true); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>

        <div>
            <?php if ($carriers !== array()) {
                echo '<datalist id="order-carriers">';
                foreach ($carriers as $carrierName) {
                    echo '<option value="' . osc_esc_html((string) $carrierName) . '"></option>';
                }
                echo '</datalist>';
            } ?>
            <?php if ($order->canShip()) { ?>
                <?php osc_admin_panel_open(__('Ship this order'), array('class' => 'billing-detail-next')); ?>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>"
                          id="order-ship-form" data-osc-confirm-empty="#order-ship-dialog">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_ship"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <?php $trackingFields('ship', $typed); ?>
                        <button type="submit" class="btn btn-submit"><?php _e('Mark shipped'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($isGoods && $order->getStatus() === Order::STATUS_SHIPPED) { ?>
                <?php osc_admin_panel_open(__('Delivery'), array('class' => 'billing-detail-next')); ?>
                    <p class="panel-subtitle"><?php echo osc_esc_html($order->awaitsCash()
                        ? __('Mark this delivered once the courier has collected the cash. That also marks it paid.')
                        : __('Mark this delivered once the parcel has arrived.')); ?></p>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_delivered"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <button type="submit" class="btn btn-submit"><?php _e('Mark delivered'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($isGoods && $order->getStatus() === Order::STATUS_SHIPPED) { ?>
                <?php osc_admin_panel_open(__('Edit tracking')); ?>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_tracking"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <?php $trackingFields('track', $typed + array(
                            'carrier'     => (string) ($fulfil['carrier'] ?? ''),
                            'tracking'    => (string) ($fulfil['tracking'] ?? ''),
                            'trackingUrl' => (string) ($fulfil['tracking_url'] ?? ''),
                        )); ?>
                        <button type="submit" class="btn btn-submit"><?php _e('Save tracking'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($order->isPending()) { ?>
                <?php osc_admin_panel_open(__('Settle this order')); ?>
                    <p class="panel-subtitle">
                        <?php if ($isGoods) {
                            _e('Mark this paid when the money has arrived: a bank transfer, or cash on delivery.');
                        } else {
                            printf(
                                osc_esc_html(__('Marking this paid adds %s credits to the account straight away. '
                                                . 'Do it when the money has arrived but the payment method could not '
                                                . 'tell us — a bank transfer, or a callback that never came through.')),
                                number_format($order->getCredits())
                            );
                        } ?>
                    </p>
                    <?php if ($gatewayWarning !== '') { ?>
                        <div class="callout-warning callout-block mb-3">
                            <div>
                                <?php echo osc_esc_html($gatewayWarning . ' ' . __('The payment may still come through '
                                    . 'once it is back. Check the provider before you mark this paid by hand.')); ?>
                                <a href="<?php echo osc_esc_html($pluginsUrl); ?>"><?php _e('Open Plugins'); ?></a>
                            </div>
                        </div>
                    <?php } ?>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_paid"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <button type="submit" class="btn btn-submit"><?php _e('Mark as paid'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($order->isPending() || ($isGoods && $order->awaitsCash())) { ?>
                <?php osc_admin_panel_open(__('Cancel this order')); ?>
                    <p class="panel-subtitle"><?php echo osc_esc_html($isGoods
                        ? __('Close an order that will not be paid. Stock held for it goes back on sale.')
                        : __('Close an order that will not be paid.')); ?></p>
                    <button type="button" class="btn btn-dim" data-osc-dialog-open="#order-cancel-dialog"><?php _e('Cancel order'); ?></button>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php osc_admin_panel_open(__('About this order')); ?>
                <p class="panel-subtitle mb-0">
                    <?php _e('An order records one attempt to pay. Shopclass stores what was bought and what '
                             . 'happened to it; the payment plugin handles the money itself and never gives '
                             . 'Shopclass the card details.'); ?>
                </p>
            <?php osc_admin_panel_close(); ?>

            <?php /* Last in the column, and the only red on the screen. A refund is the one
                     action here that takes something away, so it does not get to be the first
                     thing an admin's eye lands on. */ ?>
            <?php if ($order->isPaid()) { ?>
                <?php osc_admin_panel_open(__('Refund')); ?>
                    <?php if ($refundable) { ?>
                        <p class="panel-subtitle">
                            <?php printf(
                                osc_esc_html($isGoods
                                    ? __('Refund this payment through %s. The buyer gets the money back.')
                                    : __('Refund this payment through %s. The buyer gets the money back '
                                         . 'and the credits are taken back.')),
                                osc_esc_html($gateway->getName())
                            ); ?>
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-danger"
                                    data-osc-dialog-open="#order-refund-gateway-dialog"><?php _e('Refund'); ?></button>
                            <button type="button" class="btn btn-dim"
                                    data-osc-dialog-open="#order-refund-dialog"><?php _e('Record a refund'); ?></button>
                        </div>
                        <p class="panel-subtitle mt-3 mb-0">
                            <?php _e('Already refunded at the provider? Record it instead, so nothing is sent twice.'); ?>
                        </p>
                    <?php } else { ?>
                        <?php if ($gatewayWarning !== '') { ?>
                            <div class="callout-warning callout-block mb-3">
                                <div>
                                    <?php echo osc_esc_html($gatewayWarning . ' ' . __('This order cannot be refunded '
                                        . 'from here. Turn the plugin on, or refund it in the provider\'s dashboard '
                                        . 'and then use Record a refund.')); ?>
                                    <a href="<?php echo osc_esc_html($pluginsUrl); ?>"><?php _e('Open Plugins'); ?></a>
                                </div>
                            </div>
                        <?php } ?>
                        <?php if ($refundSent) { ?>
                            <p class="panel-subtitle">
                                <?php printf(
                                    osc_esc_html(__('A refund was sent to the payment provider on %s, but it was not '
                                                    . 'recorded here. Check the provider\'s dashboard, then record it.')),
                                    osc_esc_html((string) $order->meta(OrderStore::REFUND_REQUESTED))
                                ); ?>
                            </p>
                        <?php } ?>
                        <p class="panel-subtitle">
                            <?php _e('Record a refund you have already made through your payment provider. '
                                     . 'Shopclass never asks the provider for the money back — it only writes down '
                                     . 'that you did.'); ?>
                        </p>
                        <button type="button" class="btn btn-danger"
                                data-osc-dialog-open="#order-refund-dialog"><?php _e('Record a refund'); ?></button>
                    <?php } ?>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>
        </div>
    </div>

    <?php if ($refundable) {
        osc_admin_confirm_dialog(array(
            'id'      => 'order-refund-gateway-dialog',
            'url'     => $actionUrl,
            'fields'  => array(
                'page'   => 'billing',
                'action' => 'order_refund_gateway',
                'id'     => (int) $order->getId(),
            ),
            'title'   => sprintf(__('Refund order #%d?'), $order->getId()),
            'text'    => $isGoods
                ? sprintf(
                    __('%1$s sends %2$s back to %3$s. This cannot be undone.'),
                    $gateway->getName(),
                    osc_admin_money($order->getAmount(), $order->getCurrency()),
                    $userName
                )
                : sprintf(
                    __('%1$s sends %2$s back to the buyer, and %3$s credits are taken back from %4$s. '
                       . 'This cannot be undone.'),
                    $gateway->getName(),
                    osc_admin_money($order->getAmount(), $order->getCurrency()),
                    number_format($order->getCredits()),
                    $userName
                ),
            'confirm' => __('Refund'),
        ));
    }
    if ($order->isPending() || ($isGoods && $order->awaitsCash())) {
        osc_admin_confirm_dialog(array(
            'id'      => 'order-cancel-dialog',
            'url'     => $actionUrl,
            'fields'  => array(
                'page'   => 'billing',
                'action' => 'order_cancel',
                'id'     => (int) $order->getId(),
            ),
            'title'   => sprintf(__('Cancel order #%d?'), $order->getId()),
            'text'    => __('The buyer is not charged. This cannot be undone.'),
            'confirm' => __('Cancel order'),
        ));
    }
    if ($order->canShip()) {
        osc_admin_confirm_dialog(array(
            'id'           => 'order-ship-dialog',
            'tone'         => 'neutral',
            'confirm_form' => 'order-ship-form',
            'title'        => sprintf(__('Ship order #%d without a tracking number?'), $order->getId()),
            'text'         => __('The buyer will see the order as shipped with no carrier or tracking. You can add them later.'),
            'confirm'      => __('Mark shipped'),
        ));
    }
    if ($order->isPaid()) {
        osc_admin_confirm_dialog(array(
            'id'      => 'order-refund-dialog',
            'url'     => $actionUrl,
            'fields'  => array(
                'page'   => 'billing',
                'action' => 'order_refund',
                'id'     => (int) $order->getId(),
            ),
            'title'   => sprintf(__('Record a refund for order #%d?'), $order->getId()),
            'text'    => $isGoods
                ? __('This records that the money was given back. Nothing is sent to the payment provider.')
                : sprintf(
                    __('This takes %1$s credits back from %2$s. If they have already spent '
                       . 'them their balance will go below zero, and they will not be able to '
                       . 'spend again until it is back up.'),
                    number_format($order->getCredits()),
                    $userName
                ),
            'confirm' => __('Record the refund'),
        ));
    } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
