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
<div id="general-settings">
    <form method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="billing_post"/>
        <fieldset>
            <div class="form-horizontal">
                <?php osc_admin_page_head(__('Billing')); ?>

                <p class="form-intro">
                    <?php _e('Turn this on to sell things on your site — featured listings, posting credits, '
                             . 'or whatever a payment plugin offers. Leave it off and nothing changes: posting '
                             . 'stays free and unlimited, and the Billing menu stays hidden.'); ?>
                </p>

                <?php osc_admin_form_row_open(__('Selling on this site')); ?>
                    <?php osc_admin_checkbox(array(
                        'id'      => 'billing_enabled',
                        'name'    => 'billing_enabled',
                        'label'   => __('Enable billing'),
                        'checked' => $billingEnabled,
                        'help'    => __('Switching this off later hides the Billing menu but keeps every order '
                                        . 'and balance exactly as it is. Nothing is deleted.'),
                    )); ?>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_actions(array(
                    array('label' => __('Save settings'), 'type' => 'submit'),
                )); ?>
            </div>
        </fieldset>
    </form>

    <form method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="billing_pricing_post"/>
        <fieldset>
            <div class="form-horizontal">
                <?php osc_admin_page_head(__('Pricing')); ?>

                <p class="form-intro">
                    <?php _e('What posting costs once the free quota runs out, and what a featured '
                             . 'listing costs. These apply whether a payment plugin is installed or an '
                             . 'admin grants credits by hand.'); ?>
                </p>

                <?php osc_admin_form_row_open(__('Free listings per period'), array('for' => 'billing_free_posts_per_period')); ?>
                    <input type="number" min="0" class="input-small" id="billing_free_posts_per_period"
                           name="billing_free_posts_per_period"
                           value="<?php echo osc_esc_html((string) osc_billing_free_posts_per_period()); ?>"/>
                    <div class="help-box"><?php _e('0 means unlimited free posting.'); ?></div>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Period length (days)'), array('for' => 'billing_period_days')); ?>
                    <input type="number" min="1" class="input-small" id="billing_period_days"
                           name="billing_period_days"
                           value="<?php echo osc_esc_html((string) osc_billing_period_days()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Extra listing price (credits)'), array('for' => 'billing_publish_credits')); ?>
                    <input type="number" min="0" class="input-small" id="billing_publish_credits"
                           name="billing_publish_credits"
                           value="<?php echo osc_esc_html((string) osc_billing_publish_credits()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Featured listing price (credits)'), array('for' => 'billing_premium_credits')); ?>
                    <input type="number" min="0" class="input-small" id="billing_premium_credits"
                           name="billing_premium_credits"
                           value="<?php echo osc_esc_html((string) osc_billing_premium_credits()); ?>"/>
                    <div class="help-box"><?php _e('0 means featured listings are not for sale.'); ?></div>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Featured listing duration (days)'), array('for' => 'billing_premium_days')); ?>
                    <input type="number" min="1" class="input-small" id="billing_premium_days"
                           name="billing_premium_days"
                           value="<?php echo osc_esc_html((string) osc_billing_premium_days()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Currency'), array('for' => 'billing_currency')); ?>
                    <input type="text" maxlength="3" class="input-small" id="billing_currency"
                           name="billing_currency"
                           value="<?php echo osc_esc_html(osc_billing_currency()); ?>"/>
                    <div class="help-box"><?php _e('A 3-letter ISO 4217 code, e.g. USD, EUR.'); ?></div>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_actions(array(
                    array('label' => __('Save pricing'), 'type' => 'submit'),
                )); ?>
            </div>
        </fieldset>
    </form>

    <form method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="billing_offline_post"/>
        <fieldset>
            <div class="form-horizontal">
                <?php osc_admin_page_head(__('Bank transfer')); ?>

                <p class="form-intro">
                    <?php _e('Core\'s built-in payment method — no card processor, no API keys. A buyer '
                             . 'sees these instructions at checkout and pays outside the site; you settle '
                             . 'the order by hand once the money arrives.'); ?>
                </p>

                <?php osc_admin_form_row_open(__('Availability')); ?>
                    <?php osc_admin_checkbox(array(
                        'id'      => 'billing_offline_enabled',
                        'name'    => 'billing_offline_enabled',
                        'label'   => __('Accept bank transfer / cash payment'),
                        'checked' => osc_billing_offline_enabled(),
                        'help'    => __('Stays hidden at checkout until instructions are written below — '
                                        . 'there is nothing for a buyer to pay if they do not know where to send it.'),
                    )); ?>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Payment instructions'), array('for' => 'billing_offline_instructions')); ?>
                    <textarea id="billing_offline_instructions" name="billing_offline_instructions" rows="5"
                              class="form-control"><?php echo osc_esc_html(osc_billing_offline_instructions()); ?></textarea>
                    <div class="help-box"><?php _e('Bank details, or wherever a buyer sends the money. Shown to the '
                                                    . 'buyer exactly as written here.'); ?></div>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_actions(array(
                    array('label' => __('Save bank transfer settings'), 'type' => 'submit'),
                )); ?>
            </div>
        </fieldset>
    </form>

    <form method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="billing_upgrades_post"/>
        <fieldset>
            <div class="form-horizontal">
                <?php osc_admin_page_head(__('Upgrades')); ?>

                <p class="form-intro">
                    <?php _e('Bump, highlight and urgent are optional item upgrades, each priced and switched '
                             . 'on separately. Every one ships off — turning it on with a price of 0 makes it '
                             . 'free to every seller, not the same as leaving it off.'); ?>
                </p>

                <?php osc_admin_form_row_open(__('Bump to top')); ?>
                    <?php osc_admin_checkbox(array(
                        'id'      => 'billing_bump_enabled',
                        'name'    => 'billing_bump_enabled',
                        'label'   => __('Sell bump to top'),
                        'checked' => osc_billing_bump_enabled(),
                    )); ?>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Bump price (credits)'), array('for' => 'billing_bump_credits')); ?>
                    <input type="number" min="0" class="input-small" id="billing_bump_credits"
                           name="billing_bump_credits"
                           value="<?php echo osc_esc_html((string) osc_billing_bump_credits()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Cooldown (hours)'), array('for' => 'billing_bump_cooldown_hours')); ?>
                    <input type="number" min="1" class="input-small" id="billing_bump_cooldown_hours"
                           name="billing_bump_cooldown_hours"
                           value="<?php echo osc_esc_html((string) osc_billing_bump_cooldown_hours()); ?>"/>
                    <div class="help-box"><?php _e('How long a listing must wait before it can be bumped again.'); ?></div>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Highlight')); ?>
                    <?php osc_admin_checkbox(array(
                        'id'      => 'billing_highlight_enabled',
                        'name'    => 'billing_highlight_enabled',
                        'label'   => __('Sell highlighting'),
                        'checked' => osc_billing_highlight_enabled(),
                    )); ?>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Highlight price (credits)'), array('for' => 'billing_highlight_credits')); ?>
                    <input type="number" min="0" class="input-small" id="billing_highlight_credits"
                           name="billing_highlight_credits"
                           value="<?php echo osc_esc_html((string) osc_billing_highlight_credits()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Highlight duration (days)'), array('for' => 'billing_highlight_days')); ?>
                    <input type="number" min="1" class="input-small" id="billing_highlight_days"
                           name="billing_highlight_days"
                           value="<?php echo osc_esc_html((string) osc_billing_highlight_days()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Urgent')); ?>
                    <?php osc_admin_checkbox(array(
                        'id'      => 'billing_urgent_enabled',
                        'name'    => 'billing_urgent_enabled',
                        'label'   => __('Sell marking a listing urgent'),
                        'checked' => osc_billing_urgent_enabled(),
                    )); ?>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Urgent price (credits)'), array('for' => 'billing_urgent_credits')); ?>
                    <input type="number" min="0" class="input-small" id="billing_urgent_credits"
                           name="billing_urgent_credits"
                           value="<?php echo osc_esc_html((string) osc_billing_urgent_credits()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_row_open(__('Urgent duration (days)'), array('for' => 'billing_urgent_days')); ?>
                    <input type="number" min="1" class="input-small" id="billing_urgent_days"
                           name="billing_urgent_days"
                           value="<?php echo osc_esc_html((string) osc_billing_urgent_days()); ?>"/>
                <?php osc_admin_form_row_close(); ?>

                <?php osc_admin_form_actions(array(
                    array('label' => __('Save upgrade settings'), 'type' => 'submit'),
                )); ?>
            </div>
        </fieldset>
    </form>

    <?php osc_admin_page_head(__('Payment methods'));

    if (empty($gateways)) {
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
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
