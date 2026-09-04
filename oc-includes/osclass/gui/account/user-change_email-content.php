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
 * Change the address the account signs in with -- markup only.
 *
 * The POST is change_email_post and is CSRF-checked; core injects the token.
 * Nothing changes until the link in the confirmation email is followed.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <p class="oe-muted"><?php printf(
            osc_esc_html(_m('You currently sign in as %s.')),
            osc_esc_html(osc_logged_user_email())
        ); ?></p>

        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="user" />
            <input type="hidden" name="action" value="change_email_post" />
            <div class="oe-field">
                <label class="oe-label" for="oe-new-email"><?php
                    echo osc_esc_html(_m('New email address')); ?></label>
                <input class="oe-input" id="oe-new-email" type="email" name="new_email"
                       autocomplete="email" required aria-describedby="oe-new-email-hint" />
                <span class="oe-hint" id="oe-new-email-hint"><?php echo osc_esc_html(
                    _m('We send a link to the new address. The change takes effect when you follow it.')
                ); ?></span>
            </div>
            <div class="oe-actions">
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save')); ?></button>
            </div>
        </form>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
