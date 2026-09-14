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
 * Set a new password from an emailed link -- markup only.
 *
 * The controller has already matched userId + code before rendering; it does not
 * export the row, so both are read back from the request and carried through.
 * The POST is forgot_post and is CSRF-checked.
 */
?>
<div class="oe-form-page">
    <?php osc_show_flash_message(); ?>

    <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
        <input type="hidden" name="page" value="login" />
        <input type="hidden" name="action" value="forgot_post" />
        <input type="hidden" name="userId" value="<?php echo (int) Params::getParamInt('userId'); ?>" />
        <input type="hidden" name="code"
               value="<?php echo osc_esc_html(Params::getParamString('code')); ?>" />

        <div class="oe-field">
            <label class="oe-label" for="oe-new-password"><?php
                echo osc_esc_html(_m('New password')); ?></label>
            <input class="oe-input" id="oe-new-password" type="password" name="new_password"
                   autocomplete="new-password" required minlength="6"
                   aria-describedby="oe-new-password-hint" />
            <span class="oe-hint" id="oe-new-password-hint"><?php echo osc_esc_html(
                _m('At least six characters.')
            ); ?></span>
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-new-password2"><?php
                echo osc_esc_html(_m('Repeat the new password')); ?></label>
            <input class="oe-input" id="oe-new-password2" type="password" name="new_password2"
                   autocomplete="new-password" required minlength="6" />
        </div>
        <div class="oe-actions">
            <button class="oe-btn" type="submit"><?php
                echo osc_esc_html(_m('Save the new password')); ?></button>
        </div>
    </form>
</div>
