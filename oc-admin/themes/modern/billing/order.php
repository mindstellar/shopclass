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

use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
));

/** @var Order $order */
$order   = __get('order');
$entries = __get('entries');
$balance = (int)__get('balance');
$refundable = (bool)__get('refundable');
$dashboardUrl = (string) __get('dashboardUrl');
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
$userName = $user !== null ? ($user['s_username'] ?: $user['s_name']) : __('this user');

$base       = osc_admin_base_url(true) . '?page=billing';
$actionUrl  = osc_admin_base_url(true);

$statusWords = array(
    Order::STATUS_PENDING   => __('Pending'),
    Order::STATUS_PAID      => __('Paid'),
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
    array('label' => __('Credits'), 'value' => number_format($order->getCredits())),
);

if ($user !== null) {
    $rows[] = array(
        'label' => __('User'),
        'value' => '<a href="' . osc_esc_html($base . '&action=wallet&userId=' . $order->getUserId()) . '">'
                   . osc_esc_html($userName) . '</a>'
                   . ' <span class="text-muted">' . osc_esc_html($user['s_email']) . '</span>',
        'html'  => true,
    );
    $rows[] = array('label' => __('Balance now'), 'value' => number_format($balance) . ' ' . __('credits'));
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
            <?php osc_admin_panel_open(__('Order')); ?>
                <?php osc_admin_definition($rows); ?>
            <?php osc_admin_panel_close(); ?>

            <?php osc_admin_form_section(__('Credit movements')); ?>
            <?php if (empty($entries)) {
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
            <?php if ($order->isPending()) { ?>
                <?php osc_admin_panel_open(__('Settle this order')); ?>
                    <p class="panel-subtitle">
                        <?php printf(
                            osc_esc_html(__('Marking this paid adds %s credits to the account straight away. '
                                            . 'Do it when the money has arrived but the payment method could not '
                                            . 'tell us — a bank transfer, or a callback that never came through.')),
                            number_format($order->getCredits())
                        ); ?>
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
                                osc_esc_html(__('Refund this payment through %s. The buyer gets the money back '
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
            'text'    => sprintf(
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
            'text'    => sprintf(
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
