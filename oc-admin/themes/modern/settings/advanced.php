<?php
if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$current_host = parse_url(Params::getServerParam('HTTP_HOST'), PHP_URL_HOST);
if ($current_host === null) {
    $current_host = Params::getServerParam('HTTP_HOST');
}

//customize Head
function customHead()
{
}

osc_add_hook('admin_header', 'customHead', 10);

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Advanced Settings'),
    'help'    => __("Change advanced configuration of your Shopclass. "
                    . "<strong>Be careful</strong> when modifying default values if you're not sure what you're doing!"),
));

osc_current_admin_theme_path('parts/header.php');
?>
<div id="general-setting">
    <!-- settings form -->
    <div id="general-settings">
        <?php osc_admin_page_head(__('Advanced Settings')); ?>
        <ul id="error_list"></ul>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="advanced_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Subdomain type'); ?></div>
                        <div class="form-controls">
                            <select name="e_type" id="e_type">
                                <option value="" <?php if (osc_subdomain_type()) {
                                    ?>selected="selected"<?php
                                } ?>><?php _e('No subdomains'); ?></option>
                                <option value="category" <?php if (osc_subdomain_type() === 'category') {
                                    ?>selected="selected"<?php
                                } ?>><?php _e('Category based'); ?></option>
                                <option value="country" <?php if (osc_subdomain_type() === 'country') {
                                    ?>selected="selected"<?php
                                } ?>><?php _e('Country based'); ?></option>
                                <option value="region" <?php if (osc_subdomain_type() === 'region') {
                                    ?>selected="selected"<?php
                                } ?>><?php _e('Region based'); ?></option>
                                <option value="city" <?php if (osc_subdomain_type() === 'city') {
                                    ?>selected="selected"<?php
                                } ?>><?php _e('City based'); ?></option>
                                <option value="user" <?php if (osc_subdomain_type() === 'user') {
                                    ?>selected="selected"<?php
                                } ?>><?php _e('User based'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Host'); ?></div>
                        <div class="form-controls"><input type="text" class="xlarge" name="s_host"
                                                          value="<?php echo osc_esc_html(osc_subdomain_host()); ?>"/>
                            <div class="help-box"><?php _e('Your host is required to know the subdomain.'); ?>
                                <?php printf(
                                    __('Your current host is "%s". Add it without "www".'),
                                    $current_host
                                ); ?><?php _e('Remember to enable cookies for the subdomains too.'); ?></div>
                        </div>
                    </div>
                    <div class="clear"></div>
                    <?php osc_admin_form_actions(); ?>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
