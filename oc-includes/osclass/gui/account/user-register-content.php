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

        <?php osc_gui_field('s_name', _m('Name'), UserForm::name_text(...)); ?>
        <?php osc_gui_field('s_email', _m('Email address'), UserForm::email_text(...)); ?>
        <?php osc_gui_field('s_password', _m('Password'), UserForm::password_text(...)); ?>
        <?php osc_gui_field('s_password2', _m('Repeat password'), UserForm::check_password_text(...)); ?>

        <?php if (osc_captcha_enabled()) {
            osc_show_captcha('register');
        } ?>
        <?php osc_run_hook('user_register_form'); ?>

        <?php osc_gui_actions(array(array('label' => _m('Create account')))); ?>
    </form>
    <?php osc_run_hook('user_register_form_after'); ?>

    <p class="oe-muted"><?php echo osc_esc_html(_m('Already have an account?')); ?>
        <a href="<?php echo osc_esc_html(osc_user_login_url(osc_login_return_path())); ?>"><?php
            echo osc_esc_html(_m('Sign in')); ?></a></p>

    <?php UserForm::js_validation(); ?>
</div>
