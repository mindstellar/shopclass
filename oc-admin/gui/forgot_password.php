<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}
?>
    <div class="alert alert-info">
        <?php _e('Type your new password'); ?>.
    </div>
    <form action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="login"/>
        <input type="hidden" name="action" value="forgot_post"/>
        <input type="hidden" name="adminId" value="<?php echo Params::getParam('adminId', true); ?>"/>
        <input type="hidden" name="code" value="<?php echo Params::getParam('code', true); ?>"/>
        <div class="form-floating mb-3">
            <input id="new_password" type="password" name="new_password" class="form-control"
                   placeholder="<?php _e('New password'); ?>"
                   autocomplete="off">
            <label for="user_pass"><?php _e('New password'); ?></label>
        </div>
        <div class="form-floating mb-3">
            <input id="new_password2" type="password" name="new_password2" class="form-control"
                   placeholder="<?php _e('Repeat new password'); ?>"
                   autocomplete="off">
            <label for="user_pass"><?php _e('Repeat new password'); ?></label>
        </div>
        <?php osc_run_hook('admin_forgot_form'); ?>
        <button class="w-100 btn btn-lg btn-primary" type="submit" name="submit"
                id="submit"><?php echo osc_esc_html(__('Change password')); ?></button>
        <div class="mt-5 mb-3"><a href="<?php echo osc_base_url(); ?>"
                                  title="<?php echo osc_esc_html(sprintf(__('Back to %s'), osc_page_title())); ?>">
                <i class="text-dark bi bi-arrow-left"></i> <?php printf(__('Back to %s'), osc_page_title()); ?></a>
        </div>
    </form>
    <p id="nav">
        <a title="<?php _e('Log in'); ?>" href="<?php echo osc_admin_base_url(); ?>"><?php _e('Log in'); ?></a>
    </p>
<?php $login_js = static function () { ?>
    <script type="text/javascript">
        $(document).ready(function () {
            $(".ico-close").click(function () {
                $(this).parent().hide();
            });
            $("#new_password").focus();
        });
    </script>
<?php };
osc_add_hook('admin_login_footer', $login_js); ?>