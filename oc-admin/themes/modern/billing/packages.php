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

$packages = __get('packages');
$base     = osc_admin_base_url(true) . '?page=billing';

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
    'help'    => __('What a buyer can choose at checkout — a price, a currency and how many '
                    . 'credits it mints. Checkout reads all three from here; nothing about the '
                    . 'price ever comes from the browser.'),
    'heading' => __('Packages'),
    'buttons' => array(
        array('label' => __('Add package'), 'url' => $base . '&action=package', 'icon' => 'bi-plus-lg', 'variant' => 'primary'),
    ),
));
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(); ?>

    <?php if (empty($packages)) {
        osc_admin_empty(array(
            'title'  => __('No packages yet'),
            'text'   => __('A package is what a buyer picks at checkout. Add one to start selling credits.'),
            'action' => array('label' => __('Add package'), 'url' => $base . '&action=package'),
        ));
    } else { ?>
        <?php
        $tableRows = array();
        foreach ($packages as $package) {
            $editUrl = $base . '&action=package&id=' . $package['pk_i_id'];
            $tableRows[] = array(
                'status' => array('status' => array(
                    $package['b_enabled'] ? 'active' : 'inactive',
                    $package['b_enabled'] ? __('Enabled') : __('Disabled'),
                )),
                'name' => array('render' => static function () use ($editUrl, $package): void { ?>
                    <a href="<?php echo osc_esc_html($editUrl); ?>">
                        <?php echo osc_esc_html($package['s_name']); ?>
                    </a>
                <?php }),
                'price'   => osc_admin_money($package['i_amount'], $package['s_currency']),
                'credits' => number_format((int) $package['i_credits']),
                'actions' => array('render' => static function () use ($editUrl, $package): void { ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($editUrl); ?>">
                        <?php _e('Edit'); ?>
                    </a>
                    <button type="button" class="btn btn-danger btn-sm"
                            data-osc-dialog-open="#package-delete-<?php echo (int) $package['pk_i_id']; ?>">
                        <?php _e('Delete'); ?>
                    </button>
                <?php }),
            );
        }
        osc_admin_table(array(
            'status'  => array('label' => __('Status'), 'class' => 'col-status', 'col_name' => false),
            'name'    => __('Name'),
            'price'   => array('label' => __('Price'), 'numeric' => true),
            'credits' => array('label' => __('Credits'), 'numeric' => true),
            'actions' => __('Actions'),
        ), $tableRows, array('stack' => true));
        ?>

        <?php foreach ($packages as $package) {
            osc_admin_confirm_dialog(array(
                'id'      => 'package-delete-' . (int) $package['pk_i_id'],
                'fields'  => array(
                    'page'   => 'billing',
                    'action' => 'package_delete',
                    'id'     => (int) $package['pk_i_id'],
                ),
                'title'   => sprintf(__('Delete "%s"?'), $package['s_name']),
                'text'    => __('This only removes it from checkout. Orders already placed against it '
                                . 'keep their own record of what was paid.'),
                'confirm' => __('Delete'),
            ));
        } ?>
    <?php } ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
