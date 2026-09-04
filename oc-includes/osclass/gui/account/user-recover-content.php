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
 * Ask for a password-reset link -- markup only.
 *
 * The POST is recover_post and is CSRF-checked. Core answers the same way
 * whether or not the address is registered, so this page promises nothing that
 * would tell a stranger which addresses exist.
 */
?>
<div class="oe-form-page">
    <?php osc_show_flash_message(); ?>

    <p><?php echo osc_esc_html(
        _m('Enter the address you registered with and we will email you a link to set a new password.')
    ); ?></p>

    <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
        <input type="hidden" name="page" value="login" />
        <input type="hidden" name="action" value="recover_post" />
        <div class="oe-field">
            <label class="oe-label" for="s_email"><?php echo osc_esc_html(_m('Email address')); ?></label>
            <?php UserForm::email_text(); ?>
        </div>

        <?php if (osc_captcha_enabled()) {
            osc_show_captcha('recover_password');
        } ?>

        <div class="oe-actions">
            <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send the link')); ?></button>
            <a href="<?php echo osc_esc_html(osc_user_login_url()); ?>"><?php
                echo osc_esc_html(_m('Back to sign in')); ?></a>
        </div>
    </form>
</div>
