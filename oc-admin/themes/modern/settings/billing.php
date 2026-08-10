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
    'section' => __('Settings'),
    'title'   => __('Billing settings'),
    'help'    => __('Switch on the billing section, and see which payment plugins are installed. '
                    . 'Shopclass keeps the record of what was bought; a payment plugin handles the money.'),
));

$billingEnabled = (bool)__get('billing_enabled');
$gateways       = __get('gateways');
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Billing')); ?>

    <form method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="billing_post"/>

        <?php osc_admin_panel_open(__('Selling on this site')); ?>
            <p class="panel-subtitle">
                <?php _e('Turn this on to sell things on your site — featured listings, posting credits, '
                         . 'or whatever a payment plugin offers. Leave it off and nothing changes: posting '
                         . 'stays free and unlimited, and the Billing menu stays hidden.'); ?>
            </p>

            <div class="form-row">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="billing_enabled" name="billing_enabled"
                           value="1" <?php echo $billingEnabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="billing_enabled">
                        <?php _e('Enable billing'); ?>
                    </label>
                </div>
                <div class="help-block osc-prose">
                    <?php _e('Switching this off later hides the Billing menu but keeps every order and '
                             . 'balance exactly as it is. Nothing is deleted.'); ?>
                </div>
            </div>
        <?php osc_admin_panel_close(); ?>

        <?php osc_admin_form_actions(array(
            array('label' => __('Save settings'), 'type' => 'submit'),
        )); ?>
    </form>

    <?php osc_admin_panel_open(__('Payment methods'), array('flush' => !empty($gateways))); ?>
    <?php if (empty($gateways)) {
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
                </tr>
                </thead>
                <tbody>
                <?php foreach ($gateways as $gateway) {
                    $ready = $gateway->isConfigured(); ?>
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
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    <?php osc_admin_panel_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
