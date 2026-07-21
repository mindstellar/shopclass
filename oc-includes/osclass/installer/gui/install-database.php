<?php
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

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

// $install_nonce is a true global set by install.php at the top of the request;
// this partial is reached through display_database_config(), a function call,
// so it needs pulling in explicitly rather than inheriting the caller's scope.
global $install_nonce;

$ins_field_error = (is_array($error) && !empty($error['field'])) ? $error['field'] : null;
?>
<h1 class="ins-headline"><?php _e('Connect your database'); ?></h1>
<p class="ins-body">
    <?php _e("Enter the database details from your hosting account. If you're not sure, your host's control panel or welcome email usually has them."); ?>
</p>

<?php if (is_array($error) && !empty($error['error'])) { ?>
    <div class="ins-panel ins-panel-danger" role="alert">
        <div class="ins-panel-body"><?php echo osc_esc_html($error['error']); ?></div>
    </div>
<?php } ?>

<form action="install.php" method="post" id="ins-database-form">
    <input type="hidden" name="step" value="3" />
    <input type="hidden" name="install_nonce" value="<?php echo osc_esc_html($install_nonce); ?>" />

    <div class="ins-field">
        <label for="dbhost"><?php _e('Host'); ?></label>
        <input class="ins-input" type="text" id="dbhost" name="dbhost" value="<?php echo osc_esc_html($form_data['dbhost'] ?? 'localhost'); ?>" <?php echo $ins_field_error === 'dbhost' ? 'aria-invalid="true"' : ''; ?> autocomplete="off" />
        <div class="ins-help"><?php _e("Usually 'localhost'. If your host gave you a different one, add a port as host:port."); ?></div>
    </div>

    <div class="ins-field">
        <label for="dbname"><?php _e('Database name'); ?></label>
        <input class="ins-input" type="text" id="dbname" name="dbname" value="<?php echo osc_esc_html($form_data['dbname'] ?? 'osclass'); ?>" <?php echo $ins_field_error === 'dbname' ? 'aria-invalid="true"' : ''; ?> autocomplete="off" />
        <div class="ins-help"><?php _e('The database Shopclass should use.'); ?></div>
    </div>

    <div class="ins-field">
        <label for="username"><?php _e('Username'); ?></label>
        <input class="ins-input" type="text" id="username" name="username" value="<?php echo osc_esc_html($form_data['username'] ?? 'osclass'); ?>" <?php echo $ins_field_error === 'username' ? 'aria-invalid="true"' : ''; ?> autocomplete="off" />
        <div class="ins-help"><?php _e('Your MySQL username.'); ?></div>
    </div>

    <div class="ins-field">
        <label for="password"><?php _e('Password'); ?></label>
        <div class="ins-input-wrap">
            <input class="ins-input" type="password" id="password" name="password" value="" <?php echo $ins_field_error === 'password' ? 'aria-invalid="true"' : ''; ?> autocomplete="off" />
            <button type="button" class="ins-input-btn" data-reveal-target="password" aria-pressed="false" aria-label="<?php echo osc_esc_html(__('Show password')); ?>">
                <span class="ins-icon-show" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" /><circle cx="10" cy="10" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5" /></svg></span>
                <span class="ins-icon-hide" aria-hidden="true"><svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" /><circle cx="10" cy="10" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5" /><path d="M2 2l16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg></span>
            </button>
        </div>
        <div class="ins-help"><?php _e('Your MySQL password.'); ?></div>
    </div>

    <details class="ins-disclosure" <?php echo Params::getParam('createdb') == '1' ? 'open' : ''; ?>>
        <summary><?php _e('More options'); ?></summary>
        <div class="ins-disclosure-body">
            <div class="ins-field">
                <label for="tableprefix"><?php _e('Table prefix'); ?></label>
                <input class="ins-input" type="text" id="tableprefix" name="tableprefix" value="<?php echo osc_esc_html($form_data['tableprefix'] ?? 'oc_'); ?>" <?php echo $ins_field_error === 'tableprefix' ? 'aria-invalid="true"' : ''; ?> autocomplete="off" />
                <div class="ins-help"><?php _e('Only change this if you run more than one Shopclass install in the same database.'); ?></div>
            </div>

            <div class="ins-field ins-checkbox-row">
                <input type="checkbox" id="createdb" name="createdb" value="1" <?php echo Params::getParam('createdb') == '1' ? 'checked="checked"' : ''; ?> />
                <div>
                    <label for="createdb"><?php _e('Create the database'); ?></label>
                    <div class="ins-help"><?php _e("Turn this on if the database doesn't exist yet and you want Shopclass to create it."); ?></div>
                </div>
            </div>

            <div id="ins-createdb-fields" <?php echo Params::getParam('createdb') == '1' ? '' : 'hidden'; ?>>
                <div class="ins-field">
                    <label for="admin_username"><?php _e('Database admin username'); ?></label>
                    <input class="ins-input" type="text" id="admin_username" name="admin_username" value="<?php echo osc_esc_html($form_data['admin_username'] ?? ''); ?>" <?php echo Params::getParam('createdb') == '1' ? '' : 'disabled="disabled"'; ?> autocomplete="off" />
                </div>
                <div class="ins-field">
                    <label for="admin_password"><?php _e('Database admin password'); ?></label>
                    <input class="ins-input" type="password" id="admin_password" name="admin_password" value="" <?php echo Params::getParam('createdb') == '1' ? '' : 'disabled="disabled"'; ?> autocomplete="off" />
                    <div class="ins-help"><?php _e('An account allowed to create databases. Only used once, during setup.'); ?></div>
                </div>
            </div>
        </div>
    </details>

    <div id="ins-test-result" class="ins-test-result" role="status" aria-live="polite"></div>

    <div class="ins-actions">
        <button type="button" id="ins-test-btn" class="ins-btn ins-btn-secondary">
            <span class="ins-spinner" aria-hidden="true"></span>
            <span class="ins-btn-label"><?php _e('Test connection'); ?></span>
        </button>
        <button type="submit" class="ins-btn ins-btn-primary"><?php _e('Continue'); ?></button>
    </div>
</form>
