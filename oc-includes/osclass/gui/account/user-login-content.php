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
 * The sign-in form -- markup only, no heading and no chrome.
 *
 * The POST is login_post and is CSRF-checked; core injects the token on
 * shutdown. Field names come from UserForm because they are core's contract:
 * `email` accepts an email address or a username.
 */
?>
<div class="oe-form-page">
    <?php osc_show_flash_message(); ?>

    <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
        <input type="hidden" name="page" value="login" />
        <input type="hidden" name="action" value="login_post" />

        <div class="oe-field">
            <label class="oe-label" for="email"><?php
                echo osc_esc_html(_m('Email address or username')); ?></label>
            <?php UserForm::email_login_text(); ?>
        </div>
        <div class="oe-field">
            <label class="oe-label" for="password"><?php echo osc_esc_html(_m('Password')); ?></label>
            <?php UserForm::password_login_text(); ?>
        </div>
        <label class="oe-check">
            <?php UserForm::rememberme_login_checkbox(); ?>
            <span><?php echo osc_esc_html(_m('Keep me signed in')); ?></span>
        </label>

        <?php if (osc_captcha_enabled()) {
            osc_show_captcha('login');
        } ?>
        <?php osc_run_hook('user_form'); ?>

        <div class="oe-actions">
            <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Sign in')); ?></button>
            <a href="<?php echo osc_esc_html(osc_recover_user_password_url()); ?>"><?php
                echo osc_esc_html(_m('Forgotten your password?')); ?></a>
        </div>
    </form>

    <?php if (osc_users_enabled() && osc_user_registration_enabled()) { ?>
        <p class="oe-muted"><?php echo osc_esc_html(_m('No account yet?')); ?>
            <a href="<?php echo osc_esc_html(osc_register_account_url()); ?>"><?php
                echo osc_esc_html(_m('Create one')); ?></a></p>
    <?php } ?>
</div>
