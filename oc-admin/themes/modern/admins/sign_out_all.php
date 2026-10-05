<?php if (!defined('OC_ADMIN')) {
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

use mindstellar\security\AdminTwoFactor;

$admin = __get('admin');
if (!is_array($admin) || !isset($admin['pk_i_id']) || (int)$admin['pk_i_id'] !== osc_logged_admin_id()) {
    return;
}
$keyCount = (int)__get('admin_api_key_count');
?>
<div class="sign-out-all" id="sign-out-all">
    <?php osc_admin_panel_open(__('Sign out of all devices')); ?>
    <p class="help-box"><?php _e('Ends every admin sign-in of this account: other browsers, remembered sign-ins, and this browser too.'); ?>
        <?php if ($keyCount > 0) {
            echo osc_esc_html(sprintf(_n('This also revokes your %d API key.', 'This also revokes your %d API keys.', $keyCount), $keyCount));
        } ?></p>
    <form method="post" action="<?php echo osc_admin_base_url(true); ?>" class="two-factor-form">
        <input type="hidden" name="page" value="admins">
        <input type="hidden" name="action" value="sign_out_all">
        <div class="two-factor-field">
            <label for="sign-out-all-password"><?php _e('Your password'); ?></label>
            <input type="password" id="sign-out-all-password" name="password" class="input-text" autocomplete="current-password" required>
        </div>
        <?php if (AdminTwoFactor::enabled($admin)) { ?>
            <div class="two-factor-field">
                <label for="sign-out-all-code"><?php _e('Current code'); ?></label>
                <input type="text" id="sign-out-all-code" name="code" class="input-text two-factor-code" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="10" required>
            </div>
        <?php } ?>
        <div class="two-factor-actions">
            <button type="submit" class="btn btn-red"><?php _e('Sign out everywhere'); ?></button>
        </div>
    </form>
    <?php osc_admin_panel_close(); ?>
</div>
