<?php if (!defined('OC_ADMIN')) {
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

osc_admin_page(array(
    'section' => __('Users'),
    'title'   => __('User Settings'),
    'help'    => __('Manage the options related to users on your site. Here, you can decide if users must register or if '
                    . 'email confirmation is necessary, among other options.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <!-- settings form -->
    <?php osc_admin_page_head(__('User Settings')); ?>
    <form action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="users"/>
        <input type="hidden" name="action" value="settings_post"/>
        <fieldset>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"> <?php _e('Settings'); ?></div>
                    <div class="form-controls">
                        <?php osc_admin_checkbox(array(
                            'name'    => 'enabled_users',
                            'id'      => 'enabled_users',
                            'label'   => __('Users enabled'),
                            'checked' => osc_users_enabled(),
                        )); ?>
                    </div>
                    <div class="form-controls separate-top-medium">
                        <?php osc_admin_checkbox(array(
                            'name'    => 'enabled_user_registration',
                            'id'      => 'enabled_user_registration',
                            'label'   => __('Anyone can register'),
                            'checked' => osc_user_registration_enabled(),
                        )); ?>
                    </div>
                    <div class="form-controls separate-top-medium">
                        <?php osc_admin_checkbox(array(
                            'name'    => 'enabled_user_validation',
                            'id'      => 'enabled_user_validation',
                            'label'   => __('Users need to validate their account'),
                            'checked' => osc_user_validation_enabled(),
                        )); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"> <?php _e('Admin notifications'); ?></div>
                    <div class="form-controls">
                        <?php osc_admin_checkbox(array(
                            'name'    => 'notify_new_user',
                            'id'      => 'notify_new_user',
                            'label'   => __('When a new user is registered'),
                            'checked' => osc_notify_new_user(),
                        )); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"> <?php _e('Username blacklist'); ?></div>
                    <div class="form-controls">
                        <label id="username_blacklist" class="form-label-input">
                            <input type="text" id="username_blacklist" name="username_blacklist"
                                   value="<?php echo osc_esc_html(osc_username_blacklist()); ?>"/>
                            <span class="help-box"><?php _e('List of terms not allowed in usernames, separated by commas'); ?></span>
                        </label>
                    </div>
                </div>
                <?php osc_admin_form_actions(array(
                    array('label' => __('Save changes'), 'type' => 'submit', 'variant' => 'primary', 'attrs' => array('id' => 'save_changes')),
                )); ?>
            </div>
        </fieldset>
    </form>
    <!-- /settings form -->
<?php osc_current_admin_theme_path('parts/footer.php'); ?>