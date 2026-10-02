<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}
?>
<form name="twofactorform" id="twofactorform" action="<?php echo osc_admin_base_url(true); ?>" method="post">
    <input type="hidden" name="page" value="login"/>
    <input type="hidden" name="action" value="2fa_post"/>
    <p><?php _e('Enter the 6-digit code from your authenticator app, or one of your backup codes.'); ?></p>
    <div class="form-floating mb-3">
        <input type="text" name="code" class="form-control" id="two_factor_code" inputmode="numeric"
               autocomplete="one-time-code" autofocus required placeholder="123456">
        <label for="two_factor_code"><?php _e('Code'); ?></label>
    </div>
    <button class="w-100 btn btn-lg btn-primary" type="submit"><?php echo osc_esc_html(__('Sign in')); ?></button>
    <div class="mt-5 mb-3">
        <a href="<?php echo osc_admin_base_url(true); ?>?page=login"><i class="text-dark bi bi-arrow-left"></i> <?php _e('Back to the sign-in page'); ?></a>
    </div>
</form>
