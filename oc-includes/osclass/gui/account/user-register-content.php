<?php
if (!defined('ABS_PATH')) {
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
/**
 * The account registration form -- markup only.
 *
 * name="register" is required: UserForm::js_validation() selects the form by
 * that name, and its error list looks for #error_list. The POST is
 * register_post and is CSRF-checked.
 */
?>
<div class="oe-form-page">
    <?php osc_show_flash_message(); ?>

    <ul class="oe-list" id="error_list" role="alert"></ul>

    <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post" name="register">
        <input type="hidden" name="page" value="register" />
        <input type="hidden" name="action" value="register_post" />

        <div class="oe-field">
            <label class="oe-label" for="s_name"><?php echo osc_esc_html(_m('Name')); ?></label>
            <?php UserForm::name_text(); ?>
        </div>
        <div class="oe-field">
            <label class="oe-label" for="s_email"><?php echo osc_esc_html(_m('Email address')); ?></label>
            <?php UserForm::email_text(); ?>
        </div>
        <div class="oe-field">
            <label class="oe-label" for="s_password"><?php echo osc_esc_html(_m('Password')); ?></label>
            <?php UserForm::password_text(); ?>
        </div>
        <div class="oe-field">
            <label class="oe-label" for="s_password2"><?php echo osc_esc_html(_m('Repeat password')); ?></label>
            <?php UserForm::check_password_text(); ?>
        </div>

        <?php if (osc_captcha_enabled()) {
            osc_show_captcha('register');
        } ?>
        <?php osc_run_hook('user_register_form'); ?>

        <div class="oe-actions">
            <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Create account')); ?></button>
        </div>
    </form>

    <p class="oe-muted"><?php echo osc_esc_html(_m('Already have an account?')); ?>
        <a href="<?php echo osc_esc_html(osc_user_login_url()); ?>"><?php
            echo osc_esc_html(_m('Sign in')); ?></a></p>

    <?php UserForm::js_validation(); ?>
</div>
