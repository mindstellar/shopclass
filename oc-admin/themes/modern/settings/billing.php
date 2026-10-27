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

/**
 * The chrome around the seven declared billing forms. Each form -- its heading, its intro,
 * its route, its fields, their values and the submit row -- is core's, drawn from the
 * declaration the controller saves through. The payment-method table below them is a list
 * of what is installed and belongs to the view.
 */

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Billing settings'),
    'help'    => __('Switch on the billing section, and see which payment plugins are installed. '
                    . 'Shopclass keeps the record of what was bought; a payment plugin handles the money.'),
));

$forms    = __get('billing_forms');
$gateways = __get('gateways');
// One form per tab, so the screen has one Save button at a time. The controller checks the tab.
$tabs = array(
    'switch'   => __('Billing'),
    'pricing'  => __('Pricing'),
    'offline'  => __('Bank transfer'),
    'upgrades' => __('Upgrades'),
    'limits'   => __('Seller limits'),
    'receipts' => __('Receipts'),
    'shop'     => osc_mode_text(__('Orders'), __('Shop')),
    'methods'  => __('Payment methods'),
);
$shown = __get('billing_tabs');
if (is_array($shown)) {
    $tabs = array_intersect_key($tabs, array_flip($shown));
}
$tab = (string) __get('billing_tab');
if ($tab === 'shop' && osc_is_shop_mode()) {
    osc_register_script('admin-cod-areas', osc_asset_url_versioned(osc_current_admin_theme_js_url('cod-areas.js')), array('admin-osc'));
    osc_enqueue_script('admin-cod-areas');
}
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-settings">
    <?php osc_admin_page_head(__('Billing settings'));
    osc_admin_tab_nav(array_map(static fn ($key, $label) => array(
        'label'  => $label,
        'url'    => osc_admin_base_url(true) . '?page=settings&action=billing&tab=' . $key,
        'active' => $key === $tab,
    ), array_keys($tabs), $tabs)); ?>

    <?php if ($tab !== 'methods') {
        osc_admin_settings_form($forms[$tab]['id'], array('titles' => false) + $forms[$tab]);
    } elseif (empty($gateways)) {
        osc_admin_empty(array(
            'icon'   => 'bi-credit-card',
            'title'  => __('No payment methods installed'),
            'text'   => __('Shopclass never handles card details itself. To take payments, install a '
                           . 'payment plugin — it adds itself here once activated.'),
            'action' => array(
                'label' => __('Browse plugins'),
                'url'   => osc_admin_base_url(true) . '?page=plugins',
                'icon'  => 'bi-plugin',
            ),
        ));
    } else { ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col"><?php _e('Payment method'); ?></th>
                    <th scope="col"><?php _e('Currencies'); ?></th>
                    <th scope="col"><?php _e('Status'); ?></th>
                    <th scope="col"><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($gateways as $gateway) {
                    $ready = $gateway->isConfigured();
                    $setup = osc_billing_gateway_setup_url($gateway); ?>
                    <tr>
                        <td>
                            <strong><?php echo osc_esc_html($gateway->getName()); ?></strong>
                            <div class="text-muted osc-mono"><?php echo osc_esc_html($gateway->getId()); ?></div>
                        </td>
                        <td><?php echo osc_esc_html(
                            implode(', ', $gateway->getSupportedCurrencies()) ?: __('None declared')
                        ); ?></td>
                        <td>
                            <?php osc_admin_status(
                                $ready ? 'active' : 'inactive',
                                $ready ? __('Ready') : __('Needs setting up')
                            ); ?>
                        </td>
                        <td class="text-end">
                            <?php if ($setup !== '') { ?>
                                <a href="<?php echo osc_esc_html($setup); ?>"><?php _e('Set up'); ?></a>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
