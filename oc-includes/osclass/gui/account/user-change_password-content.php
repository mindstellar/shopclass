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
 * Change the account password -- markup only.
 *
 * The POST is change_password_post and is CSRF-checked. The field names are
 * core's own contract and are read unfiltered, so they are not renamed here.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="user" />
            <input type="hidden" name="action" value="change_password_post" />
            <div class="oe-field">
                <label class="oe-label" for="oe-password"><?php
                    echo osc_esc_html(_m('Current password')); ?></label>
                <input class="oe-input" id="oe-password" type="password" name="password"
                       autocomplete="current-password" required />
            </div>
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
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save')); ?></button>
            </div>
        </form>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
