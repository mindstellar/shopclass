<?php if (!defined('OC_ADMIN')) {
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

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
));

/** @var Order $order */
$order   = __get('order');
$entries = __get('entries');
$balance = (int)__get('balance');

// The view layer hands back '' for anything exported as null, so "absent" has to be
// tested for what it is rather than compared against null. Both of these are genuinely
// optional: the buyer's account may have been deleted, and the plugin that took the
// payment may have been uninstalled since.
$user    = is_array(__get('user')) ? __get('user') : null;
$gateway = is_object(__get('gateway')) ? __get('gateway') : null;

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
                   . osc_esc_html($user['s_username'] ?: $user['s_name']) . '</a>'
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
        // The plugin that took this payment is no longer installed. The order still has to
        // be readable and reconcilable, so the stored id stands in for the missing name.
        : sprintf(__('%s (plugin not installed)'), $order->getGateway()),
);
$rows[] = array(
    'label' => __('Reference'),
    'value' => $order->getExternalRef() ?: __('None yet'),
    'mono'  => (bool)$order->getExternalRef(),
);
$rows[] = array('label' => __('Created'), 'value' => osc_format_date($order->getDate()));
if ($order->getPaidDate() !== null) {
    $rows[] = array('label' => __('Paid'), 'value' => osc_format_date($order->getPaidDate()));
}
foreach ($order->getMeta() as $key => $value) {
    if (is_scalar($value)) {
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

            <?php osc_admin_panel_open(__('Credit movements'), array('flush' => !empty($entries))); ?>
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
                                <td><?php echo osc_esc_html(osc_format_date($entry['dt_date'])); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
            <?php osc_admin_panel_close(); ?>
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
                    <p class="panel-subtitle">
                        <?php _e('Record a refund you have already made through your payment provider. '
                                 . 'Shopclass never asks the provider for the money back — it only writes down '
                                 . 'that you did.'); ?>
                    </p>
                    <button type="button" class="btn btn-danger"
                            data-osc-dialog-open="#order-refund-dialog"><?php _e('Record a refund'); ?></button>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>
        </div>
    </div>

    <?php if ($order->isPaid()) { ?>
        <dialog id="order-refund-dialog" class="osc-dialog osc-dialog-danger">
            <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                <input type="hidden" name="page" value="billing"/>
                <input type="hidden" name="action" value="order_refund"/>
                <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                <div class="osc-dialog-body">
                    <p class="osc-dialog-title">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <?php printf(osc_esc_html(__('Record a refund for order #%d?')), $order->getId()); ?>
                    </p>
                    <p class="osc-dialog-text">
                        <?php printf(
                            osc_esc_html(__('This takes %1$s credits back from %2$s. If they have already spent '
                                            . 'them their balance will go below zero, and they will not be able to '
                                            . 'spend again until it is back up.')),
                            number_format($order->getCredits()),
                            $user !== null ? osc_esc_html($user['s_username'] ?: $user['s_name']) : __('this user')
                        ); ?>
                    </p>
                </div>
                <div class="osc-dialog-actions">
                    <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                    <button type="submit" class="btn btn-danger btn-sm"><?php _e('Record the refund'); ?></button>
                </div>
            </form>
        </dialog>
    <?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
