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
    <?php _e('Please enter your username or e-mail address'); ?>.<br/>
    <?php _e('You will receive a new password via e-mail'); ?>.
</div>
<form id="recoverform" name="recoverform" action="<?php echo osc_admin_base_url(true); ?>" method="post">
    <input type="hidden" name="page" value="login"/>
    <input type="hidden" name="action" value="recover_post"/>
    <div class="form-floating mb-3">
        <input type="text" name="email" class="form-control" id="user_email" value="" size="20" tabindex="10"
               placeholder="Enter your E-mail">
        <label for="user_email"><?php _e('E-mail'); ?></label>
    </div>
    <?php osc_show_captcha(); ?>
    <?php osc_run_hook('admin_forgot_password_form'); ?>
    <button class="w-100 btn btn-lg btn-primary" type="submit" name="submit" id="submit"><?php
        echo osc_esc_html(__('Get new password')); ?></button>
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
            $("#user_email").focus();
        });
    </script>
<?php };
osc_add_hook('admin_login_footer', $login_js); ?>