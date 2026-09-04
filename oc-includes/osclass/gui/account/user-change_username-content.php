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
 * Change the account username -- markup only.
 *
 * The POST is change_username_post and is CSRF-checked. A username can only be
 * set once on most installs, which is what the hint says rather than warns.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="user" />
            <input type="hidden" name="action" value="change_username_post" />
            <div class="oe-field">
                <label class="oe-label" for="oe-username"><?php echo osc_esc_html(_m('Username')); ?></label>
                <input class="oe-input" id="oe-username" type="text" name="s_username"
                       autocomplete="username" required aria-describedby="oe-username-hint" />
                <span class="oe-hint" id="oe-username-hint"><?php echo osc_esc_html(
                    _m('It appears on your public profile and in the address of your listings.')
                ); ?></span>
            </div>
            <div class="oe-actions">
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save')); ?></button>
            </div>
        </form>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
