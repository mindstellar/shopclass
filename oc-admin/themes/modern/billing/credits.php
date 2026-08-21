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

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
    'help'    => __('Credits are what users spend on paid features. They are added when an order is '
                    . 'paid, and you can add or remove them by hand from any account here.'),
));

$wallets     = __get('wallets');
$outstanding = (int)__get('outstanding');
$total       = (int)__get('total');
$pageNum     = (int)__get('pageNum');
$perPage     = (int)__get('perPage');

$base = osc_admin_base_url(true) . '?page=billing&action=credits';
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Credits')); ?>

    <?php osc_admin_toolbar_open(array('align' => 'end')); ?>
        <p class="billing-summary">
            <?php printf(
                osc_esc_html(__('%1$s credits held across %2$s accounts')),
                '<strong>' . number_format($outstanding) . '</strong>',
                '<strong>' . number_format($total) . '</strong>'
            ); ?>
        </p>
    <?php osc_admin_toolbar_close(); ?>

    <?php if (empty($wallets)) {
        osc_admin_empty(array(
            'icon'  => 'bi-wallet2',
            'title' => __('Nobody holds credits yet'),
            'text'  => __('An account appears here the first time credits are added to it, whether '
                          . 'by a payment or by you.'),
        ));
    } else { ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col"><?php _e('User'); ?></th>
                    <th scope="col"><?php _e('Email'); ?></th>
                    <th scope="col" class="col-numeric"><?php _e('Balance'); ?></th>
                    <th scope="col"><?php _e('Last change'); ?></th>
                    <th scope="col"><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($wallets as $wallet) {
                    $balance   = (int)$wallet['i_balance'];
                    $walletUrl = osc_admin_base_url(true) . '?page=billing&action=wallet&userId='
                                 . (int)$wallet['fk_i_user_id']; ?>
                    <tr>
                        <td>
                            <a href="<?php echo osc_esc_html($walletUrl); ?>">
                                <?php echo osc_esc_html($wallet['s_username'] ?: $wallet['s_name']); ?>
                            </a>
                        </td>
                        <td class="text-muted"><?php echo osc_esc_html($wallet['s_email']); ?></td>
                        <td class="col-numeric<?php echo $balance < 0 ? ' balance-negative' : ''; ?>">
                            <?php echo number_format($balance); ?>
                        </td>
                        <td><?php echo osc_esc_html(osc_format_date($wallet['dt_mod_date'])); ?></td>
                        <td class="text-end">
                            <a class="btn btn-secondary btn-sm"
                               href="<?php echo osc_esc_html($walletUrl); ?>"><?php _e('Open'); ?></a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php osc_admin_pager(array(
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $pageNum,
        'base_url' => osc_admin_base_url(true) . '?page=billing',
        'params'   => array('action' => 'credits'),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
