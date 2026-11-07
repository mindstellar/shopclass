<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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

require_once __DIR__ . '/fields.php';

/** @var mixed $error */

$ins_field_error = (is_array($error) && !empty($error['field'])) ? $error['field'] : null;
$ins_createdb    = Params::getParam('createdb') == '1';
$ins_field_texts = array(
    'dbhost'      => __('Check the host.'),
    'dbname'      => __('Check the database name.'),
    'username'    => __('Check the username.'),
    'password'    => __('Check this password.'),
    'tableprefix' => __('Check the table prefix.'),
);
$ins_flag = static fn (string $name): array => array(
    'invalid' => $ins_field_error === $name,
    'error'   => $ins_field_texts[$name] ?? '',
);
?>
<form action="install.php" method="post" id="ins-database-form" class="ins-stage">
    <input type="hidden" name="step" value="3" />
    <input type="hidden" name="install_nonce" value="<?php echo osc_esc_html($install_nonce); ?>" />

    <div class="ins-stage-body">
        <h1 class="ins-headline"><?php _e('Connect your database'); ?></h1>
        <p class="ins-body">
            <?php _e('Your host gives you these four details when you create a database. Look in your hosting control panel under "MySQL Databases".'); ?>
        </p>

        <?php if (is_array($error) && !empty($error['error'])) { ?>
            <div class="ins-panel ins-panel-danger" role="alert">
                <svg class="ins-icon ins-panel-icon" viewBox="0 0 16 16" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.8l6.5 11.4h-13z"/><path d="M8 6.5v3M8 11.5v.1"/></svg>
                <div class="ins-panel-body">
                    <p class="ins-panel-title"><?php _e("Couldn't connect to the database"); ?></p>
                    <p class="ins-panel-text"><?php echo osc_esc_html($error['error']); ?></p>
                </div>
            </div>
        <?php } ?>

        <div class="ins-grid2">
            <?php
            install_field(array(
                'id'    => 'dbhost',
                'label' => __('Database host'),
                'value' => $form_data['dbhost'] ?? (defined('DB_HOST') ? DB_HOST : 'localhost'),
                'help'  => __("Usually 'localhost'. If your host gave you a different one, add a port as host:port."),
                'attrs' => array('autocomplete' => 'off', 'spellcheck' => 'false'),
            ) + $ins_flag('dbhost'));
            install_field(array(
                'id'    => 'dbname',
                'label' => __('Database name'),
                'value' => $form_data['dbname'] ?? ((defined('DB_NAME') && DB_NAME !== '') ? DB_NAME : 'osclass'),
                'attrs' => array('autocomplete' => 'off', 'spellcheck' => 'false'),
            ) + $ins_flag('dbname'));
            install_field(array(
                'id'    => 'username',
                'label' => __('Username'),
                'value' => $form_data['username'] ?? ((defined('DB_USER') && DB_USER !== '') ? DB_USER : 'osclass'),
                'attrs' => array('autocomplete' => 'off', 'spellcheck' => 'false'),
            ) + $ins_flag('username'));
            install_field(array(
                'id'     => 'password',
                'type'   => 'password',
                'label'  => __('Password'),
                'value'  => '',
                'reveal' => true,
                'attrs'  => array('autocomplete' => 'off'),
            ) + $ins_flag('password'));
            ?>
        </div>

        <div id="ins-test-result" class="ins-test-result" role="status" aria-live="polite"></div>

        <details class="ins-disclosure" <?php echo $ins_createdb || in_array($ins_field_error, array('tableprefix', 'createdb', 'reset_unfinished'), true) ? 'open' : ''; ?>>
            <summary><?php _e('More options'); ?></summary>
            <div class="ins-disclosure-body">
                <?php
                install_field(array(
                    'id'    => 'tableprefix',
                    'label' => __('Table prefix'),
                    'value' => $form_data['tableprefix'] ?? (defined('DB_TABLE_PREFIX') ? DB_TABLE_PREFIX : 'oc_'),
                    'help'  => __('Only change this if you run more than one Shopclass install in the same database.'),
                    'attrs' => array('autocomplete' => 'off'),
                ) + $ins_flag('tableprefix'));
                ?>

                <label class="ins-check" for="createdb">
                    <input type="checkbox" id="createdb" name="createdb" value="1" <?php echo $ins_createdb ? 'checked="checked"' : ''; ?> />
                    <span>
                        <span class="ins-check-title"><?php _e('Create the database'); ?></span>
                        <span class="ins-help"><?php _e("Turn this on if the database doesn't exist yet and you want Shopclass to create it."); ?></span>
                    </span>
                </label>

                <label class="ins-check" for="reset_unfinished">
                    <input type="checkbox" id="reset_unfinished" name="reset_unfinished" value="1" <?php echo Params::getParam('reset_unfinished') == '1' ? 'checked="checked"' : ''; ?> <?php echo $ins_field_error === 'reset_unfinished' ? 'aria-invalid="true"' : ''; ?> />
                    <span>
                        <span class="ins-check-title"><?php _e('Remove the unfinished install'); ?></span>
                        <span class="ins-help"><?php _e('Turn this on to delete the tables of a Shopclass install that did not finish, and start again. A finished site is never touched.'); ?></span>
                    </span>
                </label>

                <div id="ins-createdb-fields" <?php echo $ins_createdb ? '' : 'hidden'; ?>>
                    <?php
                    $ins_admin_attrs = array('autocomplete' => 'off') + ($ins_createdb ? array() : array('disabled' => 'disabled'));
                    install_field(array(
                        'id'    => 'admin_username',
                        'label' => __('Database admin username'),
                        'value' => $form_data['admin_username'] ?? '',
                        'attrs' => $ins_admin_attrs,
                    ));
                    install_field(array(
                        'id'    => 'admin_password',
                        'type'  => 'password',
                        'label' => __('Database admin password'),
                        'help'  => __('An account allowed to create databases. Only used once, during setup.'),
                        'attrs' => $ins_admin_attrs,
                    ));
                    ?>
                </div>
            </div>
        </details>
    </div>

    <div class="ins-actions">
        <a class="ins-btn ins-btn-ghost ins-back" href="install.php?step=1"><?php _e('Back'); ?></a>
        <button type="button" id="ins-test-btn" class="ins-btn ins-btn-secondary">
            <span class="ins-spinner" aria-hidden="true"></span>
            <span class="ins-btn-label"><?php _e('Test connection'); ?></span>
        </button>
        <button type="submit" id="ins-connect-btn" class="ins-btn ins-btn-primary">
            <span class="ins-spinner" aria-hidden="true"></span>
            <span class="ins-btn-label"><?php _e('Connect'); ?></span>
        </button>
    </div>
</form>
