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

        <?php osc_gui_field('oe-new-password', _m('New password'), static function () { ?>
            <input class="oe-input" id="oe-new-password" type="password" name="new_password"
                   autocomplete="new-password" required minlength="<?php echo \mindstellar\user\AccountService::MIN_PASSWORD_LENGTH; ?>"
                   aria-describedby="oe-new-password-hint" />
        <?php }, sprintf(_m('At least %d characters.'), \mindstellar\user\AccountService::MIN_PASSWORD_LENGTH)); ?>
        <?php osc_gui_field('oe-new-password2', _m('Repeat the new password'), static function () { ?>
            <input class="oe-input" id="oe-new-password2" type="password" name="new_password2"
                   autocomplete="new-password" required minlength="<?php echo \mindstellar\user\AccountService::MIN_PASSWORD_LENGTH; ?>" data-match="new_password"
                   data-match-message="<?php echo osc_esc_html(_m('The two passwords do not match.')); ?>" />
        <?php }); ?>
        <?php osc_gui_actions(array(array('label' => _m('Save the new password')))); ?>
    </form>
</div>
