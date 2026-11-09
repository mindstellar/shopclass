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

osc_admin_page(array(
    'section' => __('Billing'),
    'heading' => __('Credits'),
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
        <?php
        $tableRows = array();
        foreach ($wallets as $wallet) {
            $balance   = (int)$wallet['i_balance'];
            $walletUrl = osc_admin_base_url(true) . '?page=billing&action=wallet&userId='
                         . (int)$wallet['fk_i_user_id'];
            $tableRows[] = array(
                'user' => array('render' => static function () use ($walletUrl, $wallet): void { ?>
                    <a href="<?php echo osc_esc_html($walletUrl); ?>">
                        <?php echo osc_esc_html(\mindstellar\user\UserStore::displayName($wallet)); ?>
                    </a>
                <?php }),
                'email'   => array('html' => osc_esc_html($wallet['s_email']), 'class' => 'text-muted'),
                'balance' => array('html' => number_format($balance), 'class' => $balance < 0 ? 'balance-negative' : ''),
                'changed' => array('html' => osc_admin_date($wallet['dt_mod_date'], true)),
                'open'    => array('class' => 'text-end', 'render' => static function () use ($walletUrl): void { ?>
                    <a class="btn btn-secondary btn-sm"
                       href="<?php echo osc_esc_html($walletUrl); ?>"><?php _e('Open'); ?></a>
                <?php }),
            );
        }
        osc_admin_table(array(
            'user'    => __('User'),
            'email'   => __('Email'),
            'balance' => array('label' => __('Balance'), 'numeric' => true),
            'changed' => __('Last change'),
            'open'    => array('label' => __('Actions'), 'hide_label' => true, 'col_name' => false),
        ), $tableRows, array('stack' => true));
        ?>
    <?php } ?>

    <?php osc_admin_pager(array(
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $pageNum,
        'base_url' => osc_admin_base_url(true) . '?page=billing',
        'params'   => array('action' => 'credits'),
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
