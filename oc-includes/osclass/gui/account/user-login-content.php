<?php
if (!defined('ABS_PATH')) {
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

        <?php osc_gui_field('email', _m('Email address or username'), UserForm::email_login_text(...)); ?>
        <?php osc_gui_field('password', _m('Password'), UserForm::password_login_text(...)); ?>
        <label class="oe-check">
            <?php UserForm::rememberme_login_checkbox(); ?>
            <span><?php echo osc_esc_html(_m('Keep me signed in')); ?></span>
        </label>

        <?php if (osc_captcha_enabled()) {
            osc_show_captcha('login');
        } ?>
        <?php osc_run_hook('user_login_form'); ?>

        <?php osc_gui_actions(array(
            array('label' => _m('Sign in')),
            array('label' => _m('Forgotten your password?'), 'href' => osc_recover_user_password_url(), 'style' => 'link'),
        )); ?>
    </form>
    <?php osc_run_hook('user_login_form_after'); ?>

    <?php if (osc_users_enabled() && osc_user_registration_enabled()) { ?>
        <p class="oe-muted"><?php echo osc_esc_html(_m('No account yet?')); ?>
            <a href="<?php echo osc_esc_html(osc_register_account_url(osc_login_return_path())); ?>"><?php
                echo osc_esc_html(_m('Create one')); ?></a></p>
    <?php } ?>
</div>
