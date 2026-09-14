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

            <?php
            osc_admin_text(array(
                'id'       => 'pkg-name',
                'name'     => 'name',
                'label'    => __('Name'),
                'value'    => $name,
                'required' => true,
            ));
            osc_admin_number(array(
                'id'       => 'pkg-amount',
                'name'     => 'amount',
                'label'    => __('Price'),
                'value'    => $amount,
                'min'      => 0,
                'step'     => '0.01',
                'required' => true,
                'help'     => __('Decimal currency, e.g. 9.99.'),
            ));
            osc_admin_text(array(
                'id'       => 'pkg-currency',
                'name'     => 'currency',
                'label'    => __('Currency'),
                'value'    => $currency,
                'width'    => 'num',
                'required' => true,
                'attrs'    => array('maxlength' => 3),
                'help'     => __('A 3-letter ISO 4217 code, e.g. USD.'),
            ));
            osc_admin_number(array(
                'id'       => 'pkg-credits',
                'name'     => 'credits',
                'label'    => __('Credits'),
                'value'    => $credits,
                'min'      => 1,
                'step'     => 1,
                'required' => true,
            ));
            osc_admin_number(array(
                'id'    => 'pkg-position',
                'name'  => 'position',
                'label' => __('Position'),
                'value' => $position,
                'min'   => 0,
                'step'  => 1,
                'help'  => __('Lower numbers list first at checkout.'),
            ));
            osc_admin_field(array(
                'type'      => 'checkbox',
                'row_label' => __('Availability'),
                'id'        => 'pkg-enabled',
                'name'      => 'enabled',
                'label'     => __('Offer this package at checkout'),
                'checked'   => $enabled,
            )); ?>

            <?php osc_admin_form_actions(array(
                array('label' => $isEdit ? __('Save package') : __('Add package'), 'type' => 'submit'),
            )); ?>
        </form>
    <?php osc_admin_panel_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
