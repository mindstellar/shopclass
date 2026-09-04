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
 * Account-delete confirm form -- the form only, so the themed page and the
 * system-page fallback can each supply their own heading. CSRF is injected on
 * shutdown for forms that are not marked nocsrf. The POST is delete_post; this
 * GET page does not delete anything.
 */
?>
<form class="osc-user-delete-account" action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
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
