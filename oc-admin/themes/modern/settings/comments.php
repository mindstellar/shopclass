<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            oscValidateForm(document.querySelector('form[name=comments_form]'), {
                rules: {
                    num_moderate_comments: { required: true, digits: true },
                    comments_per_page: { required: true, digits: true }
                },
                messages: {
                    num_moderate_comments: {
                        required: '<?php echo osc_esc_js(__('Moderated comments: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Moderated comments: this field must only contain numeric characters')); ?>.'
                    },
                    comments_per_page: {
                        required: '<?php echo osc_esc_js(__('Comments per page: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Comments per page: this field must only contain numeric characters')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });

            var moderate = document.querySelector('input[name="moderate_comments"]');
            function setApproved(display) {
                document.querySelectorAll('.comments_approved').forEach(function (el) { el.style.display = display; });
            }
            if (moderate) {
                if (!moderate.checked) { setApproved('none'); }
                moderate.addEventListener('change', function () {
                    setApproved(moderate.checked ? '' : 'none');
                });
            }
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Comment Settings'),
    'help'    => __("Modify the options that allow your users to publish comments on your site's listings."),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-settings">
    <ul id="error_list"></ul>
    <form name="comments_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
        <input type="hidden" name="page" value="settings"/>
        <input type="hidden" name="action" value="comments_post"/>
        <fieldset>
            <div class="form-horizontal">
                <?php osc_admin_page_head(__('Comment Settings')); ?>

                <div class="form-row">
                    <div class="form-label"><?php _e('Default comment settings'); ?></div>
                    <div class="form-controls">
                        <?php osc_admin_checkbox(array(
                            'name'    => 'enabled_comments',
                            'label'   => __('Allow people to post comments on listings'),
                            'checked' => osc_comments_enabled(),
                        )); ?>
                        <?php osc_admin_checkbox(array(
                            'name'    => 'reg_user_post_comments',
                            'label'   => __('Users must be registered and logged in to comment'),
                            'checked' => osc_reg_user_post_comments(),
                        )); ?>
                        <?php osc_admin_checkbox(array(
                            'name'    => 'enabled_recaptcha_comments',
                            'label'   => __('Require a CAPTCHA to post a comment'),
                            'checked' => osc_recaptcha_comments_enabled(),
                            'help'    => __('Needs a CAPTCHA provider configured under Settings &raquo; reCAPTCHA/Turnstile; otherwise no challenge is shown.'),
                        )); ?>
                        <?php osc_admin_checkbox(array(
                            'name'    => 'moderate_comments',
                            'label'   => __('A comment is being held for moderation'),
                            'checked' => osc_moderate_comments() != -1,
                        )); ?>
                        <div class="form-label-checkbox-offset">
                            <?php printf(
                                __('Before a comment appears, comment author must have at least %s previously approved comments'),
                                '<input type="text" class="input-small" name="num_moderate_comments" value="'
                                 . ((osc_moderate_comments() == -1) ? '0' : osc_esc_html(osc_moderate_comments()))
                                 . '" />'
                            ); ?>
                            <div class="help-box"><?php _e('If the value is zero, an administrator must always approve comments'); ?></div>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Other comment settings'); ?></div>
                    <div class="form-controls">
                        <?php printf(
                            __('Break comments into pages with %s comments per page'),
                            '<input type="text" class="input-small" name="comments_per_page" value="'
                                     . osc_esc_html(osc_comments_per_page()) . '" />'
                        ); ?>
                        <div class="help-box"><?php _e('If the value is zero all comments are shown'); ?></div>
                    </div>
                </div>

                <?php osc_admin_page_head(__('Notifications')); ?>

                <div class="form-row">
                    <div class="form-label"><?php _e('E-mail admin whenever') ?></div>
                    <div class="form-controls">
                        <?php osc_admin_checkbox(array(
                            'name'    => 'notify_new_comment',
                            'label'   => __('A new comment is posted'),
                            'checked' => osc_notify_new_comment(),
                        )); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('E-mail user whenever') ?></div>
                    <div class="form-controls">
                        <?php osc_admin_checkbox(array(
                            'name'    => 'notify_new_comment_user',
                            'label'   => __("There's a new comment on his listing"),
                            'checked' => osc_notify_new_comment_user(),
                        )); ?>
                    </div>
                </div>
                <?php osc_admin_form_actions(); ?>
            </div>
        </fieldset>
    </form>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
