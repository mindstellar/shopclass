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
    'help'    => __('Every payment taken on this site, whichever payment plugin handled it. '
                    . 'Open an order to see what it bought and to settle or refund it by hand.'),
));

$orders   = __get('orders');
$users    = __get('users');
$summary  = __get('summary');
$gateways = __get('gateways');
$filters  = __get('filters');
$total    = (int)__get('total');
$pageNum  = (int)__get('pageNum');
$perPage  = (int)__get('perPage');

$base = osc_admin_base_url(true) . '?page=billing';

// The words for each status live here rather than in the model, so they translate.
$statusWords = array(
    Order::STATUS_PENDING   => __('Pending'),
    Order::STATUS_PAID      => __('Paid'),
    Order::STATUS_FAILED    => __('Failed'),
    Order::STATUS_REFUNDED  => __('Refunded'),
    Order::STATUS_CANCELLED => __('Cancelled'),
);

$isFiltered = ($filters['status'] ?? '') !== ''
              || ($filters['gateway'] ?? '') !== ''
              || (int)($filters['user_id'] ?? 0) > 0;
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Orders')); ?>

    <?php osc_admin_toolbar_open(); ?>
        <form method="get" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>"
              class="inline nocsrf billing-filters">
            <input type="hidden" name="page" value="billing"/>

            <label class="visually-hidden" for="fStatus"><?php _e('Status'); ?></label>
            <select id="fStatus" name="status" class="form-select form-select-sm">
                <option value=""><?php _e('Any status'); ?></option>
                <?php foreach ($statusWords as $value => $word) { ?>
                    <option value="<?php echo osc_esc_html($value); ?>"
                        <?php echo ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>>
                        <?php echo osc_esc_html($word); ?>
                    </option>
                <?php } ?>
            </select>

            <?php if (!empty($gateways)) { ?>
                <label class="visually-hidden" for="fGateway"><?php _e('Payment method'); ?></label>
                <select id="fGateway" name="gateway" class="form-select form-select-sm">
                    <option value=""><?php _e('Any payment method'); ?></option>
                    <?php foreach ($gateways as $gatewayId) { ?>
                        <option value="<?php echo osc_esc_html($gatewayId); ?>"
                            <?php echo ($filters['gateway'] ?? '') === $gatewayId ? 'selected' : ''; ?>>
                            <?php echo osc_esc_html($gatewayId); ?>
                        </option>
                    <?php } ?>
                </select>
            <?php } ?>

            <button type="submit" class="btn btn-secondary btn-sm"><?php _e('Filter'); ?></button>
            <?php if ($isFiltered) { ?>
                <a class="btn btn-dim btn-sm" href="<?php echo osc_esc_html($base); ?>"><?php _e('Clear'); ?></a>
            <?php } ?>
        </form>

        <p class="billing-summary">
            <?php printf(
                osc_esc_html(__('%1$s orders · %2$s paid · %3$s awaiting payment')),
                '<strong>' . number_format($summary['count']) . '</strong>',
                '<strong>' . number_format($summary['paid']) . '</strong>',
                '<strong>' . number_format($summary['pending']) . '</strong>'
            ); ?>
        </p>
    <?php osc_admin_toolbar_close(); ?>

    <?php osc_admin_panel_open('', array('flush' => !empty($orders))); ?>
    <?php if (empty($orders)) {
        osc_admin_empty(array(
            'icon'  => 'bi-receipt',
            'title' => $isFiltered ? __('No orders match this filter') : __('No orders yet'),
            'text'  => $isFiltered
                ? __('Try a different status or payment method.')
                : __('Orders appear here as soon as a payment plugin takes its first payment.'),
            'action' => $isFiltered
                ? array('label' => __('Clear filter'), 'url' => $base)
                : array(
                    'label' => __('Set up payments'),
                    'url'   => osc_admin_base_url(true) . '?page=settings&action=billing',
                ),
        ));
    } else { ?>
        <div class="table-responsive">
            <table class="table billing-orders">
                <thead>
                <tr>
                    <th scope="col"><?php _e('Order'); ?></th>
                    <th scope="col"><?php _e('User'); ?></th>
                    <th scope="col"><?php _e('Payment method'); ?></th>
                    <th scope="col" class="col-numeric"><?php _e('Amount'); ?></th>
                    <th scope="col" class="col-numeric"><?php _e('Credits'); ?></th>
                    <th scope="col"><?php _e('Status'); ?></th>
                    <th scope="col"><?php _e('Date'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order) {
                    $user    = $users[$order->getUserId()] ?? null;
                    $orderUrl = $base . '&action=order&id=' . $order->getId(); ?>
                    <tr>
                        <td>
                            <a class="billing-order-link" href="<?php echo osc_esc_html($orderUrl); ?>">
                                <?php echo osc_esc_html('#' . $order->getId()); ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($user !== null) { ?>
                                <a href="<?php echo osc_esc_html(
                                    osc_admin_base_url(true) . '?page=users&action=edit&id=' . $order->getUserId()
                                ); ?>"><?php echo osc_esc_html($user['s_username'] ?: $user['s_name']); ?></a>
                            <?php } else { ?>
                                <span class="text-muted"><?php _e('Deleted user'); ?></span>
                            <?php } ?>
                        </td>
                        <td><?php echo osc_esc_html($order->getGateway()); ?></td>
                        <td class="col-numeric">
                            <?php echo osc_esc_html(osc_admin_money($order->getAmount(), $order->getCurrency())); ?>
                        </td>
                        <td class="col-numeric"><?php echo number_format($order->getCredits()); ?></td>
                        <td>
                            <?php osc_admin_status(
                                $order->getStatus(),
                                $statusWords[$order->getStatus()] ?? $order->getStatus()
                            ); ?>
                        </td>
                        <td><?php echo osc_esc_html(osc_format_date($order->getDate())); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    <?php osc_admin_panel_close(); ?>

    <?php osc_admin_pager(array(
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $pageNum,
        'base_url' => $base,
        'params'   => array_filter(array(
            'status'  => $filters['status'] ?? '',
            'gateway' => $filters['gateway'] ?? '',
            'userId'  => $filters['user_id'] ?? 0,
        )),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
