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
 * Account-delete confirm form -- markup only. The heading and the intro belong
 * to whatever wraps this (the theme's chrome, or core's shell), so neither is
 * printed here.
 *
 * CSRF is injected on shutdown for forms that are not marked nocsrf. The POST is
 * delete_post; this GET page does not delete anything.
 *
 * Wrapped in the account layout like every other account page: it is reached
 * from the account nav, and dropping the nav on the one destructive page left
 * the visitor no way back except the browser's own button.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <form class="osc-user-delete-account" action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="user" />
            <input type="hidden" name="action" value="delete_post" />
            <div class="oe-field">
                <label class="oe-label" for="osc-delete-account-password"><?php
                    echo osc_esc_html(_m('Password')); ?></label>
                <input class="oe-input" id="osc-delete-account-password" type="password" name="password"
                       autocomplete="current-password" required />
            </div>
            <div class="oe-actions">
                <button class="oe-btn oe-btn-danger" type="submit"><?php
                    echo osc_esc_html(_m('Delete my account')); ?></button>
                <a class="oe-muted" href="<?php echo osc_esc_html(osc_user_profile_url()); ?>"><?php
                    echo osc_esc_html(_m('Cancel')); ?></a>
            </div>
        </form>
    </div>

    <?php require __DIR__ . '/account/nav.php'; ?>
</div>
