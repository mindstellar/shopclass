<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function addHelp()
{
    echo '<p>'
         . __('Manage the options related to users on your site. Here, you can decide if users must register or if '
              . 'email confirmation is necessary, among other options.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Users'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('User Settings &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
    <!-- settings form -->
    <h2 class="render-title"><?php _e('User Settings'); ?></h2>
    <form action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="users"/>
        <input type="hidden" name="action" value="settings_post"/>
        <fieldset>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"> <?php _e('Settings'); ?></div>
                    <div class="form-controls">
                        <label id="enabled_users" class="form-label-checkbox">
                            <input type="checkbox" id="enabled_users"
                                   name="enabled_users" <?php echo(osc_users_enabled() ? 'checked="checked"' : ''); ?>
                                   value="1"/>
                            <?php _e('Users enabled'); ?>
                        </label>
                    </div>
                    <div class="form-controls separate-top-medium">
                        <label id="enabled_user_registration">
                            <input type="checkbox" id="enabled_user_registration"
                                   name="enabled_user_registration" <?php echo(osc_user_registration_enabled()
                                    ? 'checked="checked"' : ''); ?> value="1"/>
                            <?php _e('Anyone can register'); ?>
                        </label>
                    </div>
                    <div class="form-controls separate-top-medium">
                        <label id="enabled_user_validation">
                            <input type="checkbox" id="enabled_user_validation"
                                   name="enabled_user_validation" <?php echo(osc_user_validation_enabled()
                                    ? 'checked="checked"' : ''); ?> value="1"/>
                            <?php _e('Users need to validate their account'); ?>
                        </label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"> <?php _e('Admin notifications'); ?></div>
                    <div class="form-controls">
                        <label id="notify_new_user" class="form-label-checkbox">
                            <input type="checkbox" id="notify_new_user"
                                   name="notify_new_user" <?php echo(osc_notify_new_user() ? 'checked="checked"'
                                    : ''); ?> value="1"/>
                            <?php _e('When a new user is registered'); ?>
                        </label>
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
                <div class="form-actions">
                    <input type="submit" id="save_changes" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                           class="btn btn-submit"/>
                </div>
            </div>
        </fieldset>
    </form>
    <!-- /settings form -->
<?php osc_current_admin_theme_path('parts/footer.php'); ?>