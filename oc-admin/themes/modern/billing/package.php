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
));

/** @var array|null $package */
$package = __get('package');
$isEdit  = is_array($package);

$base      = osc_admin_base_url(true) . '?page=billing';
$actionUrl = osc_admin_base_url(true);

$name     = $isEdit ? $package['s_name'] : '';
$amount   = $isEdit ? number_format(((int) $package['i_amount']) / 1000000, 2, '.', '') : '';
$currency = $isEdit ? $package['s_currency'] : osc_billing_currency();
$credits  = $isEdit ? (int) $package['i_credits'] : '';
$position = $isEdit ? (int) $package['i_position'] : 0;
$enabled  = $isEdit ? (bool) $package['b_enabled'] : true;
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(
        $isEdit ? sprintf(__('Edit %s'), $name) : __('Add package'),
        array(array('label' => __('All packages'), 'url' => $base . '&action=packages', 'icon' => 'bi-arrow-left'))
    ); ?>

    <?php osc_admin_panel_open(); ?>
        <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
            <input type="hidden" name="page" value="billing"/>
            <input type="hidden" name="action" value="package_post"/>
            <?php if ($isEdit) { ?>
                <input type="hidden" name="id" value="<?php echo (int) $package['pk_i_id']; ?>"/>
            <?php } ?>

            <?php osc_admin_form_row_open(__('Name'), array('for' => 'pkg-name')); ?>
                <input type="text" class="form-control" id="pkg-name" name="name" required
                       value="<?php echo osc_esc_html($name); ?>"/>
            <?php osc_admin_form_row_close(); ?>

            <?php osc_admin_form_row_open(__('Price'), array('for' => 'pkg-amount')); ?>
                <input type="number" min="0" step="0.01" class="input-small" id="pkg-amount" name="amount" required
                       value="<?php echo osc_esc_html($amount); ?>"/>
                <div class="help-box"><?php _e('Decimal currency, e.g. 9.99.'); ?></div>
            <?php osc_admin_form_row_close(); ?>

            <?php osc_admin_form_row_open(__('Currency'), array('for' => 'pkg-currency')); ?>
                <input type="text" maxlength="3" class="input-small" id="pkg-currency" name="currency" required
                       value="<?php echo osc_esc_html($currency); ?>"/>
                <div class="help-box"><?php _e('A 3-letter ISO 4217 code, e.g. USD.'); ?></div>
            <?php osc_admin_form_row_close(); ?>

            <?php osc_admin_form_row_open(__('Credits'), array('for' => 'pkg-credits')); ?>
                <input type="number" min="1" step="1" class="input-small" id="pkg-credits" name="credits" required
                       value="<?php echo osc_esc_html((string) $credits); ?>"/>
            <?php osc_admin_form_row_close(); ?>

            <?php osc_admin_form_row_open(__('Position'), array('for' => 'pkg-position')); ?>
                <input type="number" min="0" step="1" class="input-small" id="pkg-position" name="position"
                       value="<?php echo osc_esc_html((string) $position); ?>"/>
                <div class="help-box"><?php _e('Lower numbers list first at checkout.'); ?></div>
            <?php osc_admin_form_row_close(); ?>

            <?php osc_admin_form_row_open(__('Availability')); ?>
                <?php osc_admin_checkbox(array(
                    'id'      => 'pkg-enabled',
                    'name'    => 'enabled',
                    'label'   => __('Offer this package at checkout'),
                    'checked' => $enabled,
                )); ?>
            <?php osc_admin_form_row_close(); ?>

            <?php osc_admin_form_actions(array(
                array('label' => $isEdit ? __('Save package') : __('Add package'), 'type' => 'submit'),
            )); ?>
        </form>
    <?php osc_admin_panel_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
