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
 * Account-delete confirm form -- markup only. CSRF is injected on shutdown
 * for forms that are not marked nocsrf. The POST is delete_post; this GET
 * page does not delete anything.
 */
?>
<section class="osc-user-delete-account">
    <h1><?php echo osc_esc_html(_m('Delete your account')); ?></h1>
    <p><?php echo osc_esc_html(_m('This page has not deleted the account yet. Enter your password and click Delete my account. Your listings and messages will be removed. This cannot be undone.')); ?></p>
    <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
        <input type="hidden" name="page" value="user" />
        <input type="hidden" name="action" value="delete_post" />
        <p>
            <label for="osc-delete-account-password"><?php echo osc_esc_html(_m('Password')); ?></label>
            <input id="osc-delete-account-password" type="password" name="password" autocomplete="current-password" required />
        </p>
        <p>
            <button type="submit"><?php echo osc_esc_html(_m('Delete my account')); ?></button>
            <a href="<?php echo osc_esc_html(osc_user_profile_url()); ?>"><?php echo osc_esc_html(_m('Cancel')); ?></a>
        </p>
    </form>
</section>
